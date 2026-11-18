<?php

namespace Tests\Concerns;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonInterface;

/**
 * Siembra un mensaje entrante del contacto: abre la ventana de mensajería de
 * Meta (24 h desde ese mensaje) para que los envíos de texto libre pasen.
 */
trait ReceivesInboundMessages
{
    private function receiveInbound(Conversation $conversation, ?CarbonInterface $at = null): Message
    {
        $at ??= now();

        return Message::unguarded(fn () => Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $conversation->contact_id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'in.'.uniqid(),
            'created_at' => $at,
            'updated_at' => $at,
        ]));
    }
}
