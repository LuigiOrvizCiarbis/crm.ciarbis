<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Models\Conversation;
use App\Models\Message;

/**
 * Envía un mensaje de sistema (bot/CRM) por el canal de la conversación.
 *
 * match exhaustivo con default null, NO un else que asuma WhatsApp: un canal
 * sin transporte (Telegram, Web, Mail, Manual) enviaría el mensaje por el
 * canal equivocado, o fallaría con un error opaco.
 */
class SystemMessageSender
{
    public function __construct(
        private WhatsAppMessageService $whatsAppMessageService,
        private InstagramMessageService $instagramMessageService,
        private MessengerMessageService $messengerMessageService,
    ) {}

    public function supports(Conversation $conversation): bool
    {
        return $this->serviceFor($conversation) !== null;
    }

    /**
     * @throws \RuntimeException si el canal no tiene transporte o el envío falla.
     */
    public function send(Conversation $conversation, string $content): Message
    {
        $service = $this->serviceFor($conversation);

        if (! $service) {
            throw new \RuntimeException('Canal sin transporte de mensajes del sistema');
        }

        return $service->sendSystemTextMessageFromCRM($conversation, $content);
    }

    private function serviceFor(Conversation $conversation): WhatsAppMessageService|InstagramMessageService|MessengerMessageService|null
    {
        return match ($conversation->channel?->type) {
            ChannelType::WHATSAPP => $this->whatsAppMessageService,
            ChannelType::INSTAGRAM => $this->instagramMessageService,
            ChannelType::FACEBOOK => $this->messengerMessageService,
            default => null,
        };
    }
}
