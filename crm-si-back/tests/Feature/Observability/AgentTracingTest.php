<?php

namespace Tests\Feature\Observability;

use App\Services\Observability\Langfuse\LangfuseNames;
use App\Services\Observability\Langfuse\LangfuseTracer;
use App\Services\Observability\Langfuse\ObservationType;
use App\Services\Observability\Langfuse\TraceContext;
use App\Services\Observability\Langfuse\TracedAgent;
use ArrayObject;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use RuntimeException;
use Tests\TestCase;

/**
 * El fake de laravel/ai no pasa por RunContext, así que no dispara
 * StartingStep/StepFailed: los tests de steps los disparan a mano desde la
 * respuesta fake, mientras el agente está abierto.
 */
class AgentTracingTest extends TestCase
{
    private LangfuseTracer $tracer;

    private InMemoryExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new InMemoryExporter(new ArrayObject);
        $this->tracer = LangfuseTracer::withExporter($this->exporter, [
            'enabled' => true, 'environment' => 'testing', 'release' => null, 'timeout' => 3, 'max_string_chars' => 50000,
        ]);
        $this->app->instance(LangfuseTracer::class, $this->tracer);
        TracedTestAgent::fake(['ok']);
        TracedTestAgent::$instructionCalls = 0;
        PlainTestAgent::fake(['ok']);
    }

    public function test_agent_without_active_trace_opens_its_own(): void
    {
        TracedTestAgent::make()->prompt('hola');
        $this->tracer->flush();

        $span = $this->span('test-agent');
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame('agent', $attributes['langfuse.observation.type']);
        $this->assertSame('test-agent', $attributes['langfuse.trace.name']);
        $this->assertSame('"ok"', $attributes['langfuse.observation.output']);
        $this->assertFalse($span->getParentContext()->isValid());
    }

    public function test_agent_inside_active_trace_is_child_of_current_observation(): void
    {
        $root = $this->tracer->startTrace(LangfuseNames::AUTO_REPLY, ObservationType::Agent, new TraceContext(sessionId: 'conversation-1'));
        $this->tracer->within($root, fn () => TracedTestAgent::make()->prompt('hola'));
        $root->end();
        $this->tracer->flush();

        $rootSpan = $this->span(LangfuseNames::AUTO_REPLY);
        $agentSpan = $this->span('test-agent');
        $this->assertSame($rootSpan->getContext()->getTraceId(), $agentSpan->getContext()->getTraceId());
        $this->assertSame($rootSpan->getContext()->getSpanId(), $agentSpan->getParentSpanId());
        $this->assertSame('conversation-1', $agentSpan->getAttributes()->toArray()['langfuse.session.id']);
    }

    public function test_agent_not_implementing_traced_agent_emits_no_spans(): void
    {
        PlainTestAgent::make()->prompt('hola');
        $this->tracer->flush();

        $this->assertCount(0, $this->exporter->getSpans());
    }

    public function test_failed_agent_is_marked_as_error(): void
    {
        TracedTestAgent::fake([fn () => throw new RuntimeException('proveedor caído')]);

        try {
            TracedTestAgent::make()->prompt('hola');
            $this->fail('El agente debía lanzar.');
        } catch (RuntimeException) {
        }
        $this->tracer->flush();

        $attributes = $this->span('test-agent')->getAttributes()->toArray();
        $this->assertSame('ERROR', $attributes['langfuse.observation.level']);
        $this->assertStringContainsString('proveedor caído', $attributes['langfuse.observation.status_message']);
    }

    public function test_failed_step_is_a_failed_generation_under_the_agent(): void
    {
        $this->promptWithStep(function (string $invocationId, Agent $agent, TextProvider $provider, string $model): void {
            event(new StepFailed($invocationId, 1, $agent, $provider, $model, true, new RuntimeException('timeout'), 0.1));
        });
        $this->tracer->flush();

        $generation = $this->span(LangfuseNames::RESPOND);
        $attributes = $generation->getAttributes()->toArray();
        $this->assertSame('generation', $attributes['langfuse.observation.type']);
        $this->assertSame('ERROR', $attributes['langfuse.observation.level']);
        $this->assertStringContainsString('Sos un agente de prueba.', $attributes['langfuse.observation.input']);
        $this->assertSame($this->span('test-agent')->getContext()->getSpanId(), $generation->getParentSpanId());
    }

    public function test_disabled_tracer_skips_step_work(): void
    {
        $this->app->instance(LangfuseTracer::class, LangfuseTracer::fromConfig(['enabled' => false]));
        $callsDuringStep = null;

        $this->promptWithStep(function () use (&$callsDuringStep): void {
            $callsDuringStep = TracedTestAgent::$instructionCalls;
        });

        $this->assertSame(0, $callsDuringStep);
    }

    /**
     * Corre el agente y, dentro de la respuesta fake, dispara StartingStep
     * y después $afterStarting (para cerrar o fallar el step).
     */
    private function promptWithStep(\Closure $afterStarting): void
    {
        $invocationId = null;
        Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$invocationId): void {
            $invocationId = $event->invocationId;
        });

        TracedTestAgent::fake([function ($prompt, $attachments, TextProvider $provider, string $model) use (&$invocationId, $afterStarting): string {
            $agent = new TracedTestAgent;
            TracedTestAgent::$instructionCalls = 0;
            event(new StartingStep($invocationId, 1, $agent, $provider, $model, true, [new UserMessage('hola')], null));
            $afterStarting($invocationId, $agent, $provider, $model);

            return 'ok';
        }]);

        TracedTestAgent::make()->prompt('hola');
    }

    private function span(string $name): SpanDataInterface
    {
        $span = collect($this->exporter->getSpans())->first(fn (SpanDataInterface $s) => $s->getName() === $name);
        $this->assertNotNull($span, "Falta el span {$name}");

        return $span;
    }
}

class TracedTestAgent implements Agent, TracedAgent
{
    use Promptable;

    public static int $instructionCalls = 0;

    public function instructions(): string
    {
        self::$instructionCalls++;

        return 'Sos un agente de prueba.';
    }

    public function traceName(): string
    {
        return 'test-agent';
    }

    public function generationName(): string
    {
        return LangfuseNames::RESPOND;
    }

    public function traceContext(): TraceContext
    {
        return new TraceContext(sessionId: 'test-session');
    }
}

class PlainTestAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Sos un agente sin trazar.';
    }
}
