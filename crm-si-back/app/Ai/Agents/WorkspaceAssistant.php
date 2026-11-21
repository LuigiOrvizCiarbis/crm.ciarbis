<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Assistant\ActivityStats;
use App\Ai\Tools\Assistant\ListTasks;
use App\Ai\Tools\Assistant\PipelineBreakdown;
use App\Ai\Tools\Assistant\SearchContacts;
use App\Ai\Tools\Assistant\WorkspaceSummary;
use App\Models\User;
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
 * workspace activo usando herramientas de solo lectura.
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
class WorkspaceAssistant implements Agent, Conversational, HasMiddleware, HasTools
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
     * proveedor puede reutilizar la caché de prompt entre turnos.
     */
    public function instructions(): string
    {
        $workspace = $this->user->tenant?->name ?? '';

        return <<<PROMPT
        Sos el asistente interno de Social Impulse CRM. Ayudás a {$this->user->name} con el workspace "{$workspace}". Hoy es {$this->today()}.
        Respondé en el idioma del usuario, breve y concreto. En español usá voseo rioplatense (vos, tenés, querés), nunca tú/tienes/quieres.
        Para cualquier dato del workspace usá tus herramientas; nunca inventes cifras ni nombres. Si ninguna herramienta cubre la pregunta, decilo.
        Si el usuario pide un período y la herramienta que usaste no filtra por fechas, decilo en la respuesta en lugar de atribuirle los números a ese período.
        Solo podés consultar datos: no podés crear, modificar ni enviar nada.
        Formato: texto plano, **negrita** y listas con "-". Sin tablas ni títulos.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            new WorkspaceSummary($this->user),
            new ActivityStats($this->user),
            new PipelineBreakdown($this->user),
            new ListTasks($this->user),
            new SearchContacts($this->user),
        ];
    }

    /**
     * Corta una respuesta que encadena herramientas antes de que su costo se
     * dispare.
     */
    public function middleware(): array
    {
        $maxInputTokens = (int) config('services.ai.assistant.max_input_tokens_per_run', 30000);

        return [
            Stop::unless(fn (PendingStep $step) => $step->usage->inputTokens < $maxInputTokens),
        ];
    }

    protected function maxConversationMessages(): int
    {
        return (int) config('services.ai.assistant.max_history_messages', 10);
    }

    private function today(): string
    {
        return now()->toDateString();
    }
}
