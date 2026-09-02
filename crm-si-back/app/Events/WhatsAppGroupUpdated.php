<?php

namespace App\Events;

use App\Models\WhatsAppGroup;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class WhatsAppGroupUpdated implements ShouldBroadcastNow
{
    public function __construct(public WhatsAppGroup $group) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.'.$this->group->tenant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'whatsapp-group.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->group->id,
            'conversation_id' => $this->group->conversation_id,
            'status' => $this->group->status->value,
            'total_participant_count' => $this->group->total_participant_count,
        ];
    }
}
