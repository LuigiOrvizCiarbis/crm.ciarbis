<?php

namespace App\Ai\Tools\Assistant;

use App\Ai\Tools\Assistant\Concerns\RespondsSafely;
use App\Enums\TaskStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Foto general del workspace. Todo pasa por visibleTo(): un miembro ve los
 * totales de lo que ya puede ver en el CRM, no los del equipo completo.
 */
class WorkspaceSummary implements Tool
{
    use RespondsSafely;

    public function __construct(private User $user) {}

    public function name(): string
    {
        return 'workspace_summary';
    }

    public function description(): string
    {
        return 'Resumen general del workspace actual: plan, miembros, canales, embudos y totales de contactos, conversaciones, oportunidades y tareas.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        return $this->respond(function (): array {
            $tenant = $this->user->tenant;
            $pendingTasks = Task::query()->visibleTo($this->user)
                ->whereNotIn('status', [TaskStatus::DONE, TaskStatus::CANCELED]);
            $openOpportunities = Opportunity::query()->visibleTo($this->user)->where('status', 'open');
            $openConversations = Conversation::query()->visibleTo($this->user)->open()->notArchived();

            return [
                'workspace' => [
                    'name' => $tenant->name,
                    'plan' => $tenant->planKey(),
                    'trial_days_left' => $tenant->onTrial() ? $tenant->trialDaysLeft() : null,
                    'members' => $tenant->activeMembers()->count(),
                ],
                'channels' => Channel::query()->visibleTo($this->user)->limit(20)->get(['id', 'name', 'type', 'status'])
                    ->map(fn (Channel $channel) => [
                        'name' => $channel->name,
                        'type' => strtolower($channel->type->name),
                        'status' => $channel->status,
                    ])->all(),
                'pipelines' => Pipeline::query()
                    ->with(['stages' => fn ($query) => $query->orderBy('sort_order')])
                    ->get()
                    ->map(fn (Pipeline $pipeline) => [
                        'name' => $pipeline->name,
                        'is_default' => (bool) $pipeline->is_default,
                        'stages' => $pipeline->stages->pluck('name')->all(),
                    ])->all(),
                'totals' => [
                    'contacts' => Contact::query()->visibleTo($this->user)->count(),
                    'open_conversations' => (clone $openConversations)->count(),
                    'unassigned_open_conversations' => (clone $openConversations)->unassigned()->count(),
                    'open_opportunities' => (clone $openOpportunities)->count(),
                    'open_opportunities_value' => (float) (clone $openOpportunities)->sum('value'),
                    'pending_tasks' => (clone $pendingTasks)->count(),
                    'overdue_tasks' => (clone $pendingTasks)->where('deadline', '<', now())->count(),
                ],
            ];
        });
    }
}
