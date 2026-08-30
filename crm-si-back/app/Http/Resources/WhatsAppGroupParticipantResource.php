<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppGroupParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'wa_id' => $this->wa_id,
            'display_name' => $this->display_name ?? $this->whenLoaded('contact', fn () => $this->contact?->name),
            'role' => $this->role,
            'status' => $this->status->value,
            'join_request_id' => $this->join_request_id,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'removed_at' => $this->removed_at?->toIso8601String(),
        ];
    }
}
