<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use InvalidArgumentException;

/**
 * Envía un mensaje del CRM (texto, imagen o audio) por el transporte del canal
 * de la conversación. Lo comparten el envío inmediato (MessageController) y el
 * programado (SendScheduledMessageJob), así ambos caminos se comportan igual.
 *
 * El archivo ya tiene que estar guardado en el disco `public`: acá sólo se
 * entrega a Meta.
 */
class OutboundMessageSender
{
    /** Canales que admiten programar mensajes (mail tiene otro composer). */
    public const SCHEDULABLE_CHANNELS = [ChannelType::WHATSAPP, ChannelType::INSTAGRAM, ChannelType::FACEBOOK];

    public function __construct(
        private WhatsAppMessageService $whatsAppService,
        private InstagramMessageService $instagramService,
        private MessengerMessageService $messengerService,
        private MailMessageService $mailService,
        private MessagingWindowService $messagingWindow,
    ) {}

    public function supports(Conversation $conversation): bool
    {
        return $this->serviceFor($conversation) !== null;
    }

    /**
     * $allowHumanAgentTag sólo lo pasa el envío inmediato de una persona: fuera
     * de las 24 h habilita el tag HUMAN_AGENT (Instagram/Messenger). Los
     * programados lo mandan el sistema, así que exigen la ventana estándar.
     *
     * @throws InvalidArgumentException si el canal no tiene transporte de envío
     * @throws \App\Exceptions\MetaApiException si la ventana de mensajería está cerrada
     */
    public function send(
        Conversation $conversation,
        User $user,
        string $type,
        ?string $content,
        ?string $mediaPath = null,
        ?string $mediaMime = null,
        bool $voice = false,
        bool $allowHumanAgentTag = false,
    ): Message {
        $service = $this->serviceFor($conversation)
            ?? throw new InvalidArgumentException('Este canal no admite el envío de mensajes desde el CRM.');

        $tag = null;
        if ($allowHumanAgentTag) {
            $tag = $this->messagingWindow->tagForHumanSend($conversation);
        } else {
            $this->messagingWindow->assertOpen($conversation);
        }
        // WhatsApp y Mail nunca reciben tag: tagForHumanSend sólo lo devuelve
        // para Instagram/Messenger. Spread de claves string = argumento con nombre.
        $tagArgs = $tag === null ? [] : ['messageTag' => $tag];

        return match ($type) {
            'image' => $service->sendImageMessageFromCRM(
                $conversation, $mediaPath, '/storage/'.$mediaPath, $mediaMime, $content, $user, ...$tagArgs
            ),
            'audio' => $service === $this->whatsAppService
                ? $this->whatsAppService->sendAudioMessageFromCRM(
                    $conversation, $mediaPath, '/storage/'.$mediaPath, $mediaMime, $user, $voice, ...$tagArgs
                )
                : $service->sendAudioMessageFromCRM(
                    $conversation, $mediaPath, '/storage/'.$mediaPath, $mediaMime, $user, ...$tagArgs
                ),
            default => $service->sendTextMessageFromCRM($conversation, (string) $content, $user, ...$tagArgs),
        };
    }

    private function serviceFor(Conversation $conversation): WhatsAppMessageService|InstagramMessageService|MessengerMessageService|MailMessageService|null
    {
        // match exhaustivo con default explícito: un canal sin transporte de
        // envío (Telegram, Web, Manual) no debe caer silenciosamente a WhatsApp.
        return match ($conversation->channel?->type) {
            ChannelType::WHATSAPP => $this->whatsAppService,
            ChannelType::INSTAGRAM => $this->instagramService,
            ChannelType::FACEBOOK => $this->messengerService,
            ChannelType::MAIL => $this->mailService,
            default => null,
        };
    }
}
