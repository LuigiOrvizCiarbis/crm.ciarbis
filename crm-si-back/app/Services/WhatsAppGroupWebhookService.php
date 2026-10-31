<?php

namespace App\Services;

use App\Events\WhatsAppGroupUpdated;
use App\Jobs\ReconcileWhatsAppGroupJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppGroupParticipant;
use App\Services\Concerns\ResolvesWhatsAppChannel;
use Illuminate\Support\Facades\Log;

/**
 * Procesa los cuatro campos de webhook de la Groups API. Todo corre sin Auth
 * (webhook público): withoutGlobalScopes() + filtro manual por tenant_id en
 * cada query, mismo patrón que SendBroadcastMessageJob.
 */
class WhatsAppGroupWebhookService
{
    use ResolvesWhatsAppChannel;

    public function handleLifecycleUpdate(array $value): void
    {
        $channel = $this->resolveChannelFromWebhook($value, 'group_lifecycle_update');
        if (! $channel) {
            return;
        }

        foreach ($value['groups'] ?? [] as $event) {
            match ($event['type'] ?? null) {
                'group_create' => $this->handleGroupCreate($channel, $event),
                'group_delete' => $this->handleGroupDelete($channel, $event),
                default => Log::info('group_lifecycle_update: tipo no manejado', ['type' => $event['type'] ?? null]),
            };
        }
    }

    public function handleParticipantsUpdate(array $value): void
    {
        $channel = $this->resolveChannelFromWebhook($value, 'group_participants_update');
        if (! $channel) {
            return;
        }

        foreach ($value['groups'] ?? [] as $event) {
            $group = $this->findGroup($channel, $event['group_id'] ?? null);
            if (! $group) {
                continue;
            }

            match ($event['type'] ?? null) {
                'group_participants_add' => $this->handleParticipantsAdd($group, $event),
                'group_participants_remove' => $this->handleParticipantsRemove($group, $event),
                'group_join_request_created' => $this->handleJoinRequestCreated($group, $event),
                'group_join_request_revoked' => $this->handleJoinRequestRevoked($group, $event),
                default => Log::info('group_participants_update: tipo no manejado', ['type' => $event['type'] ?? null]),
            };

            $this->refreshParticipantCount($group);
        }
    }

    public function handleSettingsUpdate(array $value): void
    {
        $channel = $this->resolveChannelFromWebhook($value, 'group_settings_update');
        if (! $channel) {
            return;
        }

        foreach ($value['groups'] ?? [] as $event) {
            $group = $this->findGroup($channel, $event['group_id'] ?? null);
            if (! $group) {
                continue;
            }

            $group->update(array_filter([
                'subject' => $event['subject'] ?? null,
                'description' => array_key_exists('description', $event) ? $event['description'] : null,
                'join_approval_mode' => $event['join_approval_mode'] ?? null,
                'profile_picture_url' => $event['profile_picture_url'] ?? null,
            ], fn ($v) => $v !== null));
        }
    }

    public function handleStatusUpdate(array $value): void
    {
        $channel = $this->resolveChannelFromWebhook($value, 'group_status_update');
        if (! $channel) {
            return;
        }

        foreach ($value['groups'] ?? [] as $event) {
            $group = $this->findGroup($channel, $event['group_id'] ?? null);
            if (! $group) {
                continue;
            }

            $suspended = (bool) ($event['suspended'] ?? false);
            $group->update([
                'suspended' => $suspended,
                'status' => $suspended ? 'suspended' : 'active',
            ]);

            broadcast(new WhatsAppGroupUpdated($group));
        }
    }

    private function handleGroupCreate(Channel $channel, array $event): void
    {
        $requestId = $event['request_id'] ?? null;
        $group = $requestId
            ? WhatsAppGroup::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->where('request_id', $requestId)
                ->first()
            : null;

        if (! $group) {
            // El webhook llegó antes de que el POST volviera y persistiéramos
            // la fila local, o Meta reenvió el evento sin request_id
            // reconocible. Reintento diferido en vez de perder el evento.
            Log::warning('group_lifecycle_update group_create: sin fila local para el request_id, reintentando', [
                'channel_id' => $channel->id,
                'request_id' => $requestId,
            ]);

            if ($requestId) {
                ReconcileWhatsAppGroupJob::dispatch($channel->id, $requestId, $event)->delay(now()->addSeconds(5));
            }

            return;
        }

        if (! empty($event['errors'])) {
            $group->update([
                'status' => 'failed',
                'error_message' => data_get($event, 'errors.0.message', 'Meta rechazó la creación del grupo.'),
            ]);

            return;
        }

        $conversation = $group->conversation ?? Conversation::create([
            'tenant_id' => $channel->tenant_id,
            'branch_id' => $channel->branch_id,
            'channel_id' => $channel->id,
            'contact_id' => null,
            'kind' => 'group',
            'status' => 'open',
            'last_message_at' => now(),
            // Un bot respondiendo en un grupo de venta es un riesgo real;
            // maybeDispatchAiReply() además tiene un guard duro por si esto
            // se reactiva a mano después.
            'ai_autoreply_enabled' => false,
        ]);

        $group->update([
            'group_id' => $event['group_id'] ?? $group->group_id,
            'invite_link' => $event['invite_link'] ?? $group->invite_link,
            'creation_timestamp' => isset($event['timestamp']) ? now()->createFromTimestamp((int) $event['timestamp']) : now(),
            'status' => 'active',
            'conversation_id' => $conversation->id,
        ]);

        broadcast(new WhatsAppGroupUpdated($group->fresh()));
    }

    private function handleGroupDelete(Channel $channel, array $event): void
    {
        $group = $this->findGroup($channel, $event['group_id'] ?? null);
        if (! $group) {
            return;
        }

        $group->update(['status' => 'deleted']);

        // Se archiva, no se borra: conserva el historial del grupo.
        if ($group->conversation) {
            $group->conversation->update(['archived_at' => now()]);
        }

        broadcast(new WhatsAppGroupUpdated($group->fresh()));
    }

    private function handleParticipantsAdd(WhatsAppGroup $group, array $event): void
    {
        foreach ($event['added_participants'] ?? [] as $participant) {
            $waId = $participant['wa_id'] ?? null;
            if (! $waId) {
                continue;
            }

            $contact = Contact::withoutGlobalScopes()
                ->where('tenant_id', $group->tenant_id)
                ->where('phone', $waId)
                ->first();

            WhatsAppGroupParticipant::updateOrCreate(
                ['whatsapp_group_id' => $group->id, 'wa_id' => $waId],
                [
                    'contact_id' => $contact?->id,
                    'user_id_bsuid' => $participant['user_id'] ?? null,
                    'parent_user_id' => $participant['parent_user_id'] ?? null,
                    'username' => $participant['username'] ?? null,
                    'display_name' => $contact?->name,
                    'status' => 'active',
                    'joined_at' => now(),
                ]
            );
        }
    }

    private function handleParticipantsRemove(WhatsAppGroup $group, array $event): void
    {
        $initiatedBy = $event['initiated_by'] ?? 'business';

        foreach ($event['removed_participants'] ?? [] as $participant) {
            $waId = $participant['wa_id'] ?? $participant['input'] ?? null;
            if (! $waId) {
                continue;
            }

            WhatsAppGroupParticipant::where('whatsapp_group_id', $group->id)
                ->where('wa_id', $waId)
                ->update([
                    'status' => 'removed',
                    'removed_at' => now(),
                    'removed_by' => $initiatedBy,
                ]);
        }
    }

    private function handleJoinRequestCreated(WhatsAppGroup $group, array $event): void
    {
        $waId = $event['wa_id'] ?? null;
        if (! $waId) {
            return;
        }

        $contact = Contact::withoutGlobalScopes()
            ->where('tenant_id', $group->tenant_id)
            ->where('phone', $waId)
            ->first();

        WhatsAppGroupParticipant::updateOrCreate(
            ['whatsapp_group_id' => $group->id, 'wa_id' => $waId],
            [
                'contact_id' => $contact?->id,
                'user_id_bsuid' => $event['user_id'] ?? null,
                'parent_user_id' => $event['parent_user_id'] ?? null,
                'username' => $event['username'] ?? null,
                'display_name' => $contact?->name,
                'status' => 'pending_approval',
                'join_request_id' => $event['join_request_id'] ?? null,
            ]
        );

        broadcast(new WhatsAppGroupUpdated($group->fresh()));
    }

    private function handleJoinRequestRevoked(WhatsAppGroup $group, array $event): void
    {
        $joinRequestId = $event['join_request_id'] ?? null;
        if (! $joinRequestId) {
            return;
        }

        WhatsAppGroupParticipant::where('whatsapp_group_id', $group->id)
            ->where('join_request_id', $joinRequestId)
            ->where('status', 'pending_approval')
            ->update(['status' => 'rejected']);
    }

    private function refreshParticipantCount(WhatsAppGroup $group): void
    {
        $group->update([
            'total_participant_count' => $group->participants()->where('status', 'active')->count(),
        ]);
    }

    private function findGroup(Channel $channel, ?string $groupId): ?WhatsAppGroup
    {
        if (! $groupId) {
            return null;
        }

        return WhatsAppGroup::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->where('group_id', $groupId)
            ->first();
    }
}
