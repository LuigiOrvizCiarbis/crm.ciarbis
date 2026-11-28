<?php

namespace App\Services\Observability\Langfuse;

use Illuminate\Events\Dispatcher;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Traza en Langfuse cualquier agente de laravel/ai que implemente TracedAgent:
 * el agente, cada step (generación) y cada herramienta. Ignora el resto.
 *
 * El dispatcher no entrega eventos hijos a listeners de la clase padre, por
 * eso se registra cada clase concreta (StreamingAgent, AgentStreamed).
 */
final class AgentTracing
{
    /** @var array<string, LangfuseObservation> clave: invocationId */
    private array $agents = [];

    /** @var array<string, LangfuseObservation> clave: invocationId:stepNumber */
    private array $steps = [];

    /** @var array<string, LangfuseObservation> clave: toolInvocationId */
    private array $tools = [];

    /**
     * Referencias de agentes terminados, para vincularlas a lo que guardó el
     * agente (p. ej. el mensaje del asistente). Acotado: en un worker de cola
     * el singleton vive entre jobs y nadie lee las de agentes anidados.
     *
     * @var array<string, TraceReference> clave: invocationId
     */
    private array $completed = [];

    private const MAX_COMPLETED = 100;

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PromptingAgent::class => 'onPrompting',
            StreamingAgent::class => 'onPrompting',
            StartingStep::class => 'onStartingStep',
            StepCompleted::class => 'onStepCompleted',
            StepFailed::class => 'onStepFailed',
            InvokingTool::class => 'onInvokingTool',
            ToolInvoked::class => 'onToolInvoked',
            ToolFailed::class => 'onToolFailed',
            AgentPrompted::class => 'onAgentCompleted',
            AgentStreamed::class => 'onAgentCompleted',
            AgentFailed::class => 'onAgentFailed',
        ];
    }

    /** Devuelve la referencia del agente terminado y la olvida. */
    public function referenceFor(string $invocationId): ?TraceReference
    {
        $reference = $this->completed[$invocationId] ?? null;
        unset($this->completed[$invocationId]);

        return $reference;
    }

    /**
     * Solo se guardan observaciones que graban: con el tracing apagado los
     * eventos de steps y herramientas no encuentran el agente y salen sin
     * evaluar instructions() ni serializar el historial.
     */
    public function onPrompting(PromptingAgent $event): void
    {
        if (! $event->prompt->agent instanceof TracedAgent) {
            return;
        }

        $observation = $this->open($event->prompt);
        if ($observation->isRecording()) {
            $this->agents[$event->invocationId] = $observation;
        }
    }

    public function onStartingStep(StartingStep $event): void
    {
        $agent = $this->agents[$event->invocationId] ?? null;
        if (! $agent || ! $event->agent instanceof TracedAgent) {
            return;
        }

        $this->steps[$this->stepKey($event->invocationId, $event->stepNumber)] = $agent
            ->generation($event->agent->generationName(), $event->model)
            ->input([
                'instructions' => (string) $event->agent->instructions(),
                'messages' => $this->serializeMessages($event->messages),
            ])
            ->metadata(['step' => $event->stepNumber]);
    }

    public function onStepCompleted(StepCompleted $event): void
    {
        $this->pull($this->steps, $this->stepKey($event->invocationId, $event->stepNumber))
            ?->output([
                'text' => $event->response->text,
                'tool_calls' => array_map(fn ($call) => $call->toArray(), $event->response->toolCalls),
                'finish_reason' => $event->response->finishReason->value,
            ])
            ->usage(UsageDetails::fromLaravelAi($event->response->usage))
            ->end();
    }

    public function onStepFailed(StepFailed $event): void
    {
        $this->pull($this->steps, $this->stepKey($event->invocationId, $event->stepNumber))
            ?->fail($event->exception)
            ->end();
    }

    public function onInvokingTool(InvokingTool $event): void
    {
        $agent = $this->agents[$event->invocationId] ?? null;
        if ($agent) {
            $this->tools[$event->toolInvocationId] = $agent
                ->child(ToolNameResolver::resolve($event->tool), ObservationType::Tool)
                ->input($event->arguments);
        }
    }

    public function onToolInvoked(ToolInvoked $event): void
    {
        $this->pull($this->tools, $event->toolInvocationId)?->output($event->result)->end();
    }

    public function onToolFailed(ToolFailed $event): void
    {
        $this->pull($this->tools, $event->toolInvocationId)?->fail($event->exception)->end();
    }

    public function onAgentCompleted(AgentPrompted $event): void
    {
        $observation = $this->pull($this->agents, $event->invocationId);
        if (! $observation) {
            return;
        }

        $observation->output($event->response->text);
        $reference = $observation->reference();
        if ($reference) {
            $this->completed[$event->invocationId] = $reference;
            $this->completed = array_slice($this->completed, -self::MAX_COMPLETED, preserve_keys: true);
        }
        $observation->end();
    }

    public function onAgentFailed(AgentFailed $event): void
    {
        $this->pull($this->agents, $event->invocationId)?->fail($event->exception)->end();
    }

    /**
     * Cuelga el agente de la herramienta que lo invocó, de la observación
     * activa (p. ej. el raíz de un job) o, si no hay ninguna, abre su trace.
     */
    private function open(AgentPrompt $prompt): LangfuseObservation
    {
        /** @var TracedAgent $agent */
        $agent = $prompt->agent;
        $tracer = app(LangfuseTracer::class);
        $parent = $this->tools[$prompt->parentToolInvocationId ?? ''] ?? $tracer->current();

        $observation = $parent->isRecording()
            ? $parent->child($agent->traceName(), ObservationType::Agent)
            : $tracer->startTrace($agent->traceName(), ObservationType::Agent, $agent->traceContext());

        return $observation->input($prompt->prompt)->metadata([
            'provider' => method_exists($prompt->provider, 'name') ? $prompt->provider->name() : class_basename($prompt->provider),
            'model' => $prompt->model,
        ]);
    }

    /**
     * @param  array<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    private function serializeMessages(array $messages): array
    {
        return array_values(array_map(fn (Message $message) => $this->serializeMessage($message), $messages));
    }

    /** @return array<string, mixed> */
    private function serializeMessage(Message $message): array
    {
        $data = ['role' => $message->role->value, 'content' => $message->content];

        if ($message instanceof AssistantMessage) {
            $data['tool_calls'] = $message->toolCalls->map(fn ($call) => $call->toArray())->all();
        } elseif ($message instanceof ToolResultMessage) {
            $data['tool_results'] = $message->toolResults->map(fn ($result) => $result->toArray())->all();
        } elseif ($message instanceof UserMessage) {
            $data['attachments'] = $message->attachments->count();
        }

        return $data;
    }

    private function stepKey(string $invocationId, int $stepNumber): string
    {
        return $invocationId.':'.$stepNumber;
    }

    /**
     * Saca y devuelve una observación del estado, o null si no existe.
     *
     * @param  array<string, LangfuseObservation>  $state
     */
    private function pull(array &$state, string $key): ?LangfuseObservation
    {
        $observation = $state[$key] ?? null;
        unset($state[$key]);

        return $observation;
    }
}
