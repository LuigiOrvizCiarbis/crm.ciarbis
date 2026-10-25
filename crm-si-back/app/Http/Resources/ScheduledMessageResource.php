<?php

namespace App\Http\Resources;

use App\Models\ScheduledMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduledMessage
 */
class ScheduledMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user?->id, 'name' => $this->user?->name]),
            'message_type' => $this->message_type,
            'content' => $this->content,
            'media_url' => $this->media_path !== null ? '/storage/'.$this->media_path : null,
            'media_mime' => $this->media_mime,
            'voice' => $this->voice,
            'status' => $this->status->value,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'message_id' => $this->message_id,
            'error_reason' => $this->error_reason,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
