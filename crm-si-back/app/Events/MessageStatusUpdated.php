<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class MessageStatusUpdated implements ShouldBroadcastNow
{
    public function __construct(public Message $message) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversations.'.$this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.status';
    }

    /**
     * Solo los campos de estado: el front hace merge sobre el mensaje existente.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'delivered_at' => $this->message->delivered_at?->toISOString(),
            'read_at' => $this->message->read_at?->toISOString(),
            'played_at' => $this->message->played_at?->toISOString(),
            'failed_at' => $this->message->failed_at?->toISOString(),
            'error_message' => $this->message->error_message,
        ];
    }
}
