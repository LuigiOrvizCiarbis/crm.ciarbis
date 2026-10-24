<?php

namespace App\Jobs;

use App\Enums\MessageDirection;
use App\Enums\NotificationType;
use App\Enums\SenderType;
use App\Models\Message;
use App\Services\Notifications\NotificationRecipients;
use App\Services\Notifications\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Notifica un mensaje entrante de un contacto a los asignados de la
 * conversación (o a quienes pueden verla si no tiene asignados). Agrupa por
 * conversación: una ráfaga de mensajes deja una sola notificación con count.
 */
class NotifyInboundMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * Mensajes con fecha real más vieja que esto no notifican: cubre la
     * importación de historial de coexistencia (delivered_at = fecha original)
     * y reintentos de webhooks muy demorados.
     */
    public const MAX_AGE_MINUTES = 30;

    public int $tries = 3;

    public function __construct(public int $messageId) {}

    public function handle(NotificationService $notifications, NotificationRecipients $recipients): void
    {
        $message = Message::withoutGlobalScopes()
            ->with(['conversation' => fn ($query) => $query->withoutGlobalScopes()->with(['contact', 'channel', 'whatsappGroup'])])
            ->find($this->messageId);
        $conversation = $message?->conversation;

        if (! $message || ! $conversation
            || $message->direction !== MessageDirection::INBOUND
            || $message->sender_type !== SenderType::CONTACT
            || ($message->delivered_at ?? $message->created_at)->lt(now()->subMinutes(self::MAX_AGE_MINUTES))) {
            return;
        }

        $notifications->notify(
            $recipients->forConversation($conversation),
            $conversation->tenant_id,
            NotificationType::MESSAGE_RECEIVED,
            [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'contact_name' => $conversation->isGroup()
                    ? ($conversation->whatsappGroup?->subject ?? $conversation->contact?->name)
                    : ($conversation->contact?->name ?? $conversation->contact?->phone),
                'channel_type' => $conversation->channel?->type?->value,
                'message_type' => $message->message_type?->value,
                'preview' => Str::limit(trim((string) $message->content), 140),
                'url' => '/chats?chat='.$conversation->id,
            ],
            'conversation:'.$conversation->id,
        );
    }
}
