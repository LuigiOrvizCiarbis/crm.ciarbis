<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsAppGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'conversation_id' => $this->conversation_id,
            'opportunity_id' => $this->opportunity_id,
            'group_id' => $this->group_id,
            'subject' => $this->subject,
            'description' => $this->description,
            'join_approval_mode' => $this->join_approval_mode,
            'invite_link' => $this->invite_link,
            'status' => $this->status->value,
            'suspended' => (bool) $this->suspended,
            'total_participant_count' => (int) $this->total_participant_count,
            'profile_picture_url' => $this->profile_picture_url,
            'error_message' => $this->error_message,
            'participants' => WhatsAppGroupParticipantResource::collection($this->whenLoaded('participants')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
