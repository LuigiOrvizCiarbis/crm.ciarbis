<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Estado de derivación de una conversación tras cualquier cambio (creada,
 * tomada, resuelta, cancelada). Lleva el estado completo de la derivación, no
 * un delta. `ai_autoreply_enabled` sólo viaja cuando lo fijó la derivación
 * (al crearla): al resolverse por un mensaje humano, el mensaje se guarda
 * antes de que el servicio de envío apague la IA y el valor leído sería viejo.
 */
class HumanHandoffUpdated implements ShouldBroadcastNow
{
    public function __construct(public Conversation $conversation, public ?bool $aiAutoreplyEnabled = null) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversations.'.$this->conversation->id)];
    }

    public function broadcastAs(): string
    {
        return 'human-handoff.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'assigned_to' => $this->conversation->assigned_to,
            'human_handoff' => $this->conversation->activeHumanHandoff,
            ...($this->aiAutoreplyEnabled !== null ? ['ai_autoreply_enabled' => $this->aiAutoreplyEnabled] : []),
        ];
    }
}
