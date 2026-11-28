<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Assistant\ActivityStats;
use App\Ai\Tools\Assistant\CreateTask;
use App\Ai\Tools\Assistant\ListTasks;
use App\Ai\Tools\Assistant\PipelineBreakdown;
use App\Ai\Tools\Assistant\SearchContacts;
use App\Ai\Tools\Assistant\WorkspaceSummary;
use App\Models\Task;
use App\Models\User;
use App\Services\Observability\Langfuse\LangfuseNames;
use App\Services\Observability\Langfuse\TraceContext;
use App\Services\Observability\Langfuse\TracedAgent;
use App\Services\Tasks\TaskCreator;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\RepairToolCalls;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Middleware\Stop;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;

/**
 * Asistente interno del CRM: responde preguntas del equipo sobre el
 * workspace activo y, con confirmación del usuario, crea tareas.
 *
 * El proveedor llega resuelto (key BYOK del workspace o de la plataforma) y
 * el historial lo guarda el SDK por TenantMembership, así los hilos de un
 * usuario no se mezclan entre workspaces.
 */
#[MaxSteps(5)]
#[MaxTokens(2048)]
#[Timeout(90)]
#[RepairToolCalls]
#[CacheToolDefinitions]
#[CacheInstructions]
class WorkspaceAssistant implements Agent, Conversational, HasMiddleware, HasTools, TracedAgent
{
    use Promptable, RemembersConversations;

    /**
     * @param  array<string, mixed>  $providerConfig  Configuración para Ai::build().
     */
    public function __construct(
        public User $user,
        public array $providerConfig,
        public string $modelName,
    ) {}

    public function provider(): Provider
    {
        return Ai::build($this->providerConfig);
    }

    public function model(): string
    {
        return $this->modelName;
    }

    /**
     * Solo la fecha, sin hora: el prefijo queda estable durante el día y el
     * proveedor puede reutilizar la caché de prompt entre turnos. El día de la
     * semana permite resolver "mañana" o "el viernes" sin otra herramienta.
     */
    public function instructions(): string
    {
        $workspace = $this->user->tenant?->name ?? '';
        $timezone = $this->user->preferredTimezone();
        $today = now($timezone)->locale('es')->isoFormat('dddd YYYY-MM-DD');
        $actions = $this->canCreateTasks()
            ? 'Podés consultar datos y crear tareas con create_task; el usuario confirma cada tarea antes de que se cree, así que no digas que la creaste hasta tener el resultado. '
                .'Las fechas relativas ("mañana a las 10") se interpretan en la zona horaria del usuario; si dio un día sin hora, usá 09:00, salvo que sea hoy: ahí usá 23:59; si no dio fecha, no pongas vencimiento. '
                .'No podés modificar ni enviar nada más.'
            : 'Solo podés consultar datos: no podés crear, modificar ni enviar nada.';

        return <<<PROMPT
        Sos el asistente interno de Social Impulse CRM. Ayudás a {$this->user->name} con el workspace "{$workspace}". Hoy es {$today} (zona horaria {$timezone}).
        Respondé en el idioma del usuario, breve y concreto. En español usá voseo rioplatense (vos, tenés, querés), nunca tú/tienes/quieres.
        Para cualquier dato del workspace usá tus herramientas; nunca inventes cifras ni nombres. Si ninguna herramienta cubre la pregunta, decilo.
        Si el usuario pide un período y la herramienta que usaste no filtra por fechas, decilo en la respuesta en lugar de atribuirle los números a ese período.
        {$actions}
        Formato: texto plano, **negrita** y listas con "-". Sin tablas ni títulos.
        PROMPT;
    }

    /**
     * create_task se registra siempre, aunque el usuario no tenga permiso: si
     * se lo quitan entre la pausa y la confirmación, el SDK necesita encontrar
     * la herramienta para reanudar el turno. La propia herramienta rechaza al
     * usuario sin permiso, y el prompt solo le ofrece crear tareas a quien puede.
     */
    public function tools(): iterable
    {
        return [
            new WorkspaceSummary($this->user),
            new ActivityStats($this->user),
            new PipelineBreakdown($this->user),
            new ListTasks($this->user),
            new SearchContacts($this->user),
            new CreateTask($this->user, app(TaskCreator::class)),
        ];
    }

    /**
     * Corta una respuesta que encadena herramientas antes de que su costo se
     * dispare, y deja de llamar al modelo si el usuario ya cortó la conexión
     * (el controlador ignora el abort para registrar el paso en curso).
     */
    public function middleware(): array
    {
        $maxInputTokens = (int) config('services.ai.assistant.max_input_tokens_per_run', 30000);

        return [
            Stop::when(fn () => connection_aborted() === 1),
            Stop::unless(fn (PendingStep $step) => $step->usage->inputTokens < $maxInputTokens),
        ];
    }

    protected function maxConversationMessages(): int
    {
        return (int) config('services.ai.assistant.max_history_messages', 10);
    }

    private function canCreateTasks(): bool
    {
        return $this->user->can('create', Task::class);
    }

    public function traceName(): string
    {
        return LangfuseNames::WORKSPACE_ASSISTANT;
    }

    public function generationName(): string
    {
        return LangfuseNames::ASSISTANT_STEP;
    }

    public function traceContext(): TraceContext
    {
        $conversationId = $this->currentConversation();

        return new TraceContext(
            sessionId: $conversationId ? 'assistant-'.$conversationId : null,
            userId: 'user-'.$this->user->id,
            metadata: ['tenant_id' => $this->user->tenant_id],
            tags: ['workspace-assistant'],
        );
    }
}
