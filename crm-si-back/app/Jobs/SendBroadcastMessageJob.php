<?php

namespace App\Jobs;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\MarketingConsentStatus;
use App\Enums\TemplateCategory;
use App\Events\BroadcastResultsUpdated;
use App\Models\BroadcastRecipient;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\BroadcastConversationResolver;
use App\Services\UnitedStatesPhoneDetector;
use App\Services\WhatsAppTemplateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Envía un mensaje de plantilla de WhatsApp a UN destinatario como parte de
 * una difusión. El dispatcher despacha un job por destinatario con delay
 * escalonado para no exceder el throughput de Meta.
 *
 * - tries=1: nunca reintentar — Meta puede haber aceptado el mensaje aunque
 *   el job falle después, y las plantillas de marketing no deben reintentarse
 *   dentro de las 24h (error 131049).
 *
 * conversationId es nullable y contactId/channelId van al final del
 * constructor a propósito: ConversationController::bulkBroadcast() llama a
 * este job con 5 argumentos posicionales sobre conversaciones ya existentes,
 * y durante un deploy puede haber jobs ya serializados en la cola. Reordenar
 * el constructor rompería ambos casos.
 */
class SendBroadcastMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public ?int $conversationId,
        public int $templateId,
        public array $components,
        public ?int $senderId,
        public int $tenantId,
        public ?int $broadcastRecipientId = null,
        public ?int $contactId = null,
        public ?int $channelId = null,
    ) {
        // Cola propia: sin esto compite con SyncMailChannelJob (corre cada
        // minuto, timeout de 180s) en la cola default, y una difusión puede
        // quedar detrás de un mail sync lento sin ningún aviso.
        $this->onQueue('broadcasts');
    }

    public function handle(WhatsAppTemplateService $templateService, BroadcastConversationResolver $resolver): void
    {
        $recipient = $this->findRecipient();

        // El job corre sin usuario autenticado: TenantScope no filtra nada,
        // por eso withoutGlobalScopes + filtro manual por tenant en TODAS las cargas.
        $conversation = $this->conversationId !== null
            ? Conversation::withoutGlobalScopes()
                ->with(['channel.whatsappConfig', 'contact'])
                ->where('tenant_id', $this->tenantId)
                ->find($this->conversationId)
            : null;

        if (! $conversation && $this->contactId !== null && $this->channelId !== null) {
            $contact = Contact::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($this->contactId);
            $channel = Channel::withoutGlobalScopes()->with('whatsappConfig')->where('tenant_id', $this->tenantId)->find($this->channelId);

            if (! $contact || ! $channel) {
                Log::warning('SendBroadcastMessageJob: contacto o canal no encontrado en el tenant', [
                    'tenant_id' => $this->tenantId,
                    'contact_id' => $this->contactId,
                    'channel_id' => $this->channelId,
                ]);

                $this->failRecipient($recipient, 'No se encontró un recurso necesario para realizar el envío.');

                return;
            }

            try {
                $conversation = $resolver->findOrCreate($contact, $channel);
            } catch (\Throwable $e) {
                Log::error('SendBroadcastMessageJob: no se pudo crear la conversación', [
                    'tenant_id' => $this->tenantId,
                    'contact_id' => $this->contactId,
                    'channel_id' => $this->channelId,
                    'error' => $e->getMessage(),
                ]);

                $this->failRecipient($recipient, 'No se pudo abrir la conversación con el contacto: '.$e->getMessage());

                return;
            }

            // Escribir el vínculo ANTES de llamar a Meta, no después: si se
            // escribiera después y el envío tirara excepción, quedaría una
            // conversación creada sin rastro en el recipient, y un reintento
            // manual crearía otra.
            if ($recipient && $recipient->conversation_id === null) {
                $recipient->update(['conversation_id' => $conversation->id]);
            }
        }

        $template = WhatsAppTemplate::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->find($this->templateId);

        $sender = $this->senderId === null
            ? null
            : User::where('tenant_id', $this->tenantId)->find($this->senderId);

        if (! $conversation || ! $template || ($this->senderId !== null && ! $sender)) {
            Log::warning('SendBroadcastMessageJob: recurso no encontrado en el tenant', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversationId,
                'template_id' => $this->templateId,
                'sender_id' => $this->senderId,
            ]);

            $this->failRecipient($recipient, 'No se encontró un recurso necesario para realizar el envío.');

            return;
        }

        // Guarda de integridad: la plantilla debe seguir aprobada y pertenecer
        // al mismo número de WhatsApp que el canal de la conversación.
        $configId = $conversation->channel?->whatsappConfig?->id;

        if (! $template->status->isApproved() || $configId === null || $template->whatsapp_config_id !== $configId) {
            Log::warning('SendBroadcastMessageJob: plantilla no válida para el canal', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversationId,
                'template_id' => $this->templateId,
                'template_status' => $template->status->value,
                'template_config_id' => $template->whatsapp_config_id,
                'channel_config_id' => $configId,
            ]);

            $this->failRecipient($recipient, 'La plantilla ya no está aprobada para este canal.');

            return;
        }

        // Una campaña programada pudo guardar destinatarios antes de que se
        // filtraran los de EE.UU., o la plantilla pudo recategorizarse a
        // marketing entre la creación y el envío. Meta aceptaría el mensaje sin
        // entregarlo, consumiendo cupo del límite de 24h.
        if ($template->category === TemplateCategory::Marketing
            && app(UnitedStatesPhoneDetector::class)->isUnitedStates($conversation->contact?->phone)) {
            $this->failRecipient($recipient, 'Meta no entrega plantillas de marketing a números de Estados Unidos.');

            return;
        }

        // El consentimiento pudo cambiar entre la creación de la campaña y el
        // envío (una programada puede dispararse días después). 131050 es
        // definitivo: si el contacto pidió no recibir marketing, no se manda.
        if ($template->category === TemplateCategory::Marketing
            && $conversation->contact?->marketing_consent_status === MarketingConsentStatus::Denied) {
            $this->failRecipient($recipient, 'El contacto pidió no recibir mensajes de marketing.');

            return;
        }

        try {
            $message = $templateService->sendTemplateMessage($conversation, $template, $this->components, $sender);
            if ($recipient) {
                $recipient->update([
                    'message_id' => $message->id,
                    'status' => BroadcastRecipientStatus::Sent,
                    'sent_at' => now(),
                    'error' => null,
                ]);
                $recipient->campaign->refreshDeliveryStatus();
                broadcast(new BroadcastResultsUpdated($recipient->broadcast_campaign_id, $recipient->id));
            }
        } catch (\Throwable $e) {
            // Fallo aislado: no rompe el resto del lote de la difusión.
            Log::error('SendBroadcastMessageJob: error enviando plantilla', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversationId,
                'template_id' => $this->templateId,
                'error' => $e->getMessage(),
            ]);
            $this->failRecipient($recipient, $e->getMessage());
        }
    }

    private function failRecipient(?BroadcastRecipient $recipient, string $error): void
    {
        if (! $recipient) {
            return;
        }

        $recipient->update([
            'status' => BroadcastRecipientStatus::Failed,
            'error' => mb_substr($error, 0, 2000),
        ]);
        $recipient->campaign->refreshDeliveryStatus();
        broadcast(new BroadcastResultsUpdated($recipient->broadcast_campaign_id, $recipient->id));
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->broadcastRecipientId === null) {
            return;
        }

        $this->failRecipient($this->findRecipient(), $exception->getMessage());
    }

    private function findRecipient(): ?BroadcastRecipient
    {
        if ($this->broadcastRecipientId === null) {
            return null;
        }

        return BroadcastRecipient::query()
            ->whereKey($this->broadcastRecipientId)
            ->whereHas('campaign', fn ($query) => $query->withoutGlobalScopes()->where('tenant_id', $this->tenantId))
            ->first();
    }
}
