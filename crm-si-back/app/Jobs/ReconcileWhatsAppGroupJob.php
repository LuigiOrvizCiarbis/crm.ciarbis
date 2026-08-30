<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\WhatsAppGroup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * El webhook group_lifecycle_update (group_create) puede llegar antes de que
 * el POST /{phone_number_id}/groups vuelva y persistamos la fila local con
 * su request_id. Este job reintenta el match unas pocas veces con delay en
 * vez de perder el evento.
 */
class ReconcileWhatsAppGroupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(
        public int $channelId,
        public string $requestId,
        public array $event,
    ) {}

    public function handle(): void
    {
        $channel = Channel::withoutGlobalScopes()->find($this->channelId);
        if (! $channel) {
            return;
        }

        $group = WhatsAppGroup::withoutGlobalScopes()
            ->where('channel_id', $this->channelId)
            ->where('request_id', $this->requestId)
            ->first();

        if (! $group) {
            // Todavía no existe: reintentar vía el mecanismo normal de retry
            // del job (tries=3), no un loop propio.
            if ($this->attempts() >= $this->tries) {
                Log::error('ReconcileWhatsAppGroupJob: agotados los reintentos sin encontrar el grupo', [
                    'channel_id' => $this->channelId,
                    'request_id' => $this->requestId,
                ]);

                return;
            }

            $this->release(5);

            return;
        }

        if (! empty($this->event['errors'])) {
            $group->update([
                'status' => 'failed',
                'error_message' => data_get($this->event, 'errors.0.message', 'Meta rechazó la creación del grupo.'),
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
            'ai_autoreply_enabled' => false,
        ]);

        $group->update([
            'group_id' => $this->event['group_id'] ?? $group->group_id,
            'invite_link' => $this->event['invite_link'] ?? $group->invite_link,
            'status' => 'active',
            'conversation_id' => $conversation->id,
        ]);
    }
}
