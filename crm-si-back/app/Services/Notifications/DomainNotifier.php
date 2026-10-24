<?php

namespace App\Services\Notifications;

use App\Enums\ChannelType;
use App\Enums\NotificationType;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\HumanHandoff;
use App\Models\ProductImport;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;

/**
 * Traduce eventos de negocio a notificaciones in-app. Concentra la forma de
 * `data` de cada tipo: el front arma el texto con i18n a partir de estos campos.
 */
class DomainNotifier
{
    public function __construct(
        private NotificationService $notifications,
        private NotificationRecipients $recipients,
    ) {}

    /**
     * @param  list<int>  $addedUserIds
     * @param  list<int>  $removedUserIds
     */
    public function conversationAssignmentChanged(Conversation $conversation, array $addedUserIds, array $removedUserIds, ?User $actor): void
    {
        $data = [
            'conversation_id' => $conversation->id,
            'contact_name' => $conversation->contact?->name ?? $conversation->contact?->phone,
            'actor_name' => $actor?->name,
            'url' => '/chats?chat='.$conversation->id,
        ];

        $this->notifications->notify(
            $this->recipients->users($conversation->tenant_id, $addedUserIds),
            $conversation->tenant_id,
            NotificationType::CONVERSATION_ASSIGNED,
            $data,
            exceptUserId: $actor?->id,
        );

        $this->notifications->notify(
            $this->recipients->users($conversation->tenant_id, $removedUserIds),
            $conversation->tenant_id,
            NotificationType::CONVERSATION_UNASSIGNED,
            $data,
            exceptUserId: $actor?->id,
        );
    }

    public function handoffRequested(HumanHandoff $handoff, Conversation $conversation): void
    {
        if ($handoff->assigned_to === null) {
            return;
        }

        $this->notifications->notify(
            $this->recipients->users($handoff->tenant_id, [(int) $handoff->assigned_to]),
            $handoff->tenant_id,
            NotificationType::HANDOFF_REQUESTED,
            [
                'conversation_id' => $conversation->id,
                'handoff_id' => $handoff->id,
                'contact_name' => $conversation->contact?->name ?? $conversation->contact?->phone,
                'reason' => $handoff->reason,
                'summary' => $handoff->summary,
                'url' => '/chats?chat='.$conversation->id,
            ],
            'handoff:'.$handoff->id,
        );
    }

    public function taskAssigned(Task $task, ?User $actor): void
    {
        if ($task->assigned_to === null) {
            return;
        }

        $this->notifications->notify(
            $this->recipients->users($task->tenant_id, [(int) $task->assigned_to]),
            $task->tenant_id,
            NotificationType::TASK_ASSIGNED,
            [
                'task_id' => $task->id,
                'task_name' => $task->name,
                'deadline' => $task->deadline?->toIso8601String(),
                'actor_name' => $actor?->name,
                'url' => '/tareas',
            ],
            exceptUserId: $actor?->id,
        );
    }

    public function importFinished(ProductImport $import): void
    {
        if ($import->requested_by === null || ! in_array($import->status, ['completed', 'failed'], true)) {
            return;
        }

        $this->notifications->notify(
            $this->recipients->users($import->tenant_id, [(int) $import->requested_by]),
            $import->tenant_id,
            $import->status === 'completed' ? NotificationType::IMPORT_COMPLETED : NotificationType::IMPORT_FAILED,
            [
                'import_id' => $import->id,
                'resource' => $import->resource,
                'filename' => $import->original_filename,
                'result' => $import->status === 'completed' ? $import->result : null,
                'url' => $import->resource === 'contacts' ? '/contactos' : '/catalogo',
            ],
        );
    }

    public function channelTokenInvalid(Channel $channel): void
    {
        $recipients = $this->recipients->owners($channel->tenant_id)
            ->merge($channel->user_id ? $this->recipients->users($channel->tenant_id, [(int) $channel->user_id]) : []);

        $this->notifications->notify(
            $recipients,
            $channel->tenant_id,
            NotificationType::CHANNEL_TOKEN_INVALID,
            [
                'channel_id' => $channel->id,
                'channel_name' => $channel->name,
                'channel_type' => $channel->type instanceof ChannelType ? $channel->type->value : $channel->type,
                'url' => '/configuracion',
            ],
            'channel:'.$channel->id.':token',
        );
    }

    public function trialExpiring(Tenant $tenant, int $daysLeft): void
    {
        $this->notifications->notify(
            $this->recipients->owners($tenant->id),
            $tenant->id,
            NotificationType::TRIAL_EXPIRING,
            [
                'days_left' => $daysLeft,
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                'url' => '/pricing',
            ],
            'trial:'.$tenant->id.':'.$daysLeft,
        );
    }
}
