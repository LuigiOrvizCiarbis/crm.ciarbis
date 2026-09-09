<?php

namespace App\Events;

use App\Models\Message;
use App\Models\MessageReaction;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class MessageReactionUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public int $messageId,
        public int $conversationId,
        public array $summary,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversations.'.$this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.reaction';
    }

    /**
     * Sin `reacted_by_me`: este evento se dispara sin usuario autenticado
     * (webhook o worker), así que ese flag saldría siempre en false. El front
     * lo deriva de `reactor_user_ids` contra su propio currentUserId.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'conversation_id' => $this->conversationId,
            'reactions' => $this->summary,
        ];
    }

    public static function forMessage(Message $message): self
    {
        return new self(
            $message->id,
            $message->conversation_id,
            MessageReaction::summaryFor($message),
        );
    }
}
