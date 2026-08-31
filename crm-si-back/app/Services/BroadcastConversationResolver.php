<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class BroadcastConversationResolver
{
    /**
     * Busca al contacto y al canal por id dentro de un tenant y resuelve su
     * conversación. Pensado para código que corre sin Auth (colas, webhooks):
     * TenantScope y BranchScope no filtran nada en ese contexto, así que
     * cargar por id sin más dejaría cruzar tenants si algún id viniera mal.
     */
    public function resolve(int $tenantId, int $contactId, int $channelId): Conversation
    {
        $contact = Contact::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->find($contactId);

        $channel = Channel::withoutGlobalScopes()
            ->with('whatsappConfig')
            ->where('tenant_id', $tenantId)
            ->find($channelId);

        if (! $contact || ! $channel) {
            throw new \RuntimeException('Contacto o canal no encontrado en el tenant.');
        }

        return $this->findOrCreate($contact, $channel);
    }

    /**
     * Encuentra o crea la conversación entre un contacto y un canal.
     *
     * firstOrCreate no es atómico (SELECT y luego INSERT): dos llamadas
     * concurrentes para el mismo par (contacto, canal) — un contacto en dos
     * campañas simultáneas es un caso real — pueden crear dos conversaciones.
     * El índice parcial `conversations_tenant_contact_channel_uniq` (ver
     * migración broadcast_recipients_by_contact) hace que la segunda tire
     * QueryException, que se atrapa relayendo la fila ganadora.
     */
    public function findOrCreate(Contact $contact, Channel $channel): Conversation
    {
        try {
            $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
                [
                    'tenant_id' => $channel->tenant_id,
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                ],
                [
                    'status' => 'open',
                    'last_message_at' => now(),
                    'branch_id' => $contact->branch_id ?? $channel->branch_id,
                    'ai_autoreply_enabled' => (bool) $channel->whatsappConfig?->ai_autoreply_default,
                ]
            );
        } catch (QueryException $e) {
            $conversation = Conversation::withoutGlobalScopes()
                ->where('tenant_id', $channel->tenant_id)
                ->where('contact_id', $contact->id)
                ->where('channel_id', $channel->id)
                ->first();

            if (! $conversation) {
                Log::error('BroadcastConversationResolver: no se pudo crear ni releer la conversación', [
                    'tenant_id' => $channel->tenant_id,
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        if (! $conversation->pipeline_stage_id) {
            $defaultStage = PipelineStage::withoutGlobalScopes()
                ->where('tenant_id', $channel->tenant_id)
                ->where(fn (Builder $query): Builder => $query
                    ->where('is_default', true)
                    ->orWhereNotNull('id'))
                ->orderByDesc('is_default')
                ->orderBy('sort_order', 'asc')
                ->first();

            if ($defaultStage) {
                $conversation->update(['pipeline_stage_id' => $defaultStage->id]);
            }
        }

        return $conversation->load(['channel.whatsappConfig', 'contact']);
    }
}
