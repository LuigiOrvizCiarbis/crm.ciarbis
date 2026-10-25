<?php

namespace App\Events;

use App\Http\Resources\ScheduledMessageResource;
use App\Models\ScheduledMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Cualquier cambio de un mensaje programado (alta, edición, cancelación,
 * envío o fallo). El front reemplaza el item por id; los estados terminales
 * lo sacan de la barra de programados.
 */
class ScheduledMessageUpdated implements ShouldBroadcastNow
{
    public function __construct(public ScheduledMessage $scheduledMessage) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversations.'.$this->scheduledMessage->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'scheduled-message.updated';
    }

    public function broadcastWith(): array
    {
        return (new ScheduledMessageResource($this->scheduledMessage->loadMissing('user')))->resolve();
    }
}
