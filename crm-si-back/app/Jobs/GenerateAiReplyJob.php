<?php

namespace App\Jobs;

use App\Enums\ChannelType;
use App\Models\AiConfig;
use App\Models\Conversation;
use App\Services\AiReplyService;
use App\Services\InstagramMessageService;
use App\Services\MessengerMessageService;
use App\Services\MailMessageService;
use App\Services\WhatsAppMessageService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Genera y envía la respuesta automática de IA para una conversación.
 *
 * - ShouldBeUnique por conversación + delay en el dispatch: una ráfaga de
 *   mensajes entrantes consecutivos produce UNA sola respuesta que considera
 *   todo el historial pendiente.
 * - tries=1: nunca reintentar automáticamente — un retry después de un envío
 *   parcial duplicaría mensajes al cliente de WhatsApp.
 */
class GenerateAiReplyJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * TTL del lock de unicidad. Sin esto, un worker muerto a mitad de job
     * dejaría el lock huérfano y la conversación no recibiría más respuestas.
     */
    public int $uniqueFor = 180;

    public function __construct(
        public int $conversationId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->conversationId;
    }

    public function handle(
        AiReplyService $aiReplyService,
        WhatsAppMessageService $whatsAppMessageService,
        InstagramMessageService $instagramMessageService,
        MessengerMessageService $messengerMessageService,
        MailMessageService $mailMessageService,
    ): void {
        $conversation = Conversation::withoutGlobalScopes()->find($this->conversationId);

        if (! $conversation) {
            return;
        }

        // Re-chequear: un humano pudo intervenir (handoff) durante el delay.
        if (! $conversation->ai_autoreply_enabled) {
            return;
        }

        // BYOK estricto: sin config de IA del tenant, deshabilitada, o sin API
        // key propia → no se responde. El job corre sin usuario autenticado,
        // por eso withoutGlobalScopes + filtro manual por tenant.
        $aiConfig = AiConfig::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->first();

        if (! $aiConfig || ! $aiConfig->enabled || ! $aiConfig->getDecryptedApiKey()) {
            Log::info('GenerateAiReplyJob: sin config de IA activa para el tenant', [
                'conversation_id' => $conversation->id,
                'tenant_id' => $conversation->tenant_id,
            ]);

            return;
        }

        $reply = $aiReplyService->respond($conversation, $aiConfig);

        if ($reply === null) {
            Log::warning('GenerateAiReplyJob: sin respuesta de IA', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        // Última verificación antes de enviar, por si el operador respondió
        // mientras la IA generaba.
        if (! $conversation->refresh()->ai_autoreply_enabled) {
            return;
        }

        // El transporte depende del canal de la conversación: mismas firmas de
        // envío en los cuatro servicios.
        //
        // match exhaustivo con default que aborta, NO un else que asuma WhatsApp:
        // un canal sin transporte de IA (Telegram, Web, Manual) enviaría el
        // mensaje por el canal equivocado, o fallaría con un error opaco.
        $service = match ($conversation->channel?->type) {
            ChannelType::WHATSAPP => $whatsAppMessageService,
            ChannelType::INSTAGRAM => $instagramMessageService,
            ChannelType::FACEBOOK => $messengerMessageService,
            ChannelType::MAIL => $mailMessageService,
            default => null,
        };

        if (! $service) {
            Log::warning('GenerateAiReplyJob: canal sin transporte de IA', [
                'conversation_id' => $conversation->id,
                'channel_type' => $conversation->channel?->type?->value,
            ]);

            return;
        }

        $service->sendSystemTextMessageFromCRM($conversation, $reply);
    }
}
