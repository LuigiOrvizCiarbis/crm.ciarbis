<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BroadcastCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'channel' => $this->whenLoaded('channel', fn (): array => [
                'id' => $this->channel->id,
                'name' => $this->channel->name,
                'type' => $this->channel->type->value,
            ]),
            'template' => $this->whenLoaded('template', fn (): array => [
                'id' => $this->template->id,
                'name' => $this->template->name,
                'language' => $this->template->language,
            ]),
            'audience_filters' => $this->audience_filters,
            'audience_count' => $this->audience_count,
            'sent_count' => (int) ($this->sent_count ?? 0),
            'error_count' => (int) ($this->error_count ?? 0),
            'pending_count' => (int) ($this->pending_count ?? 0),
            'estimated_cost_usd' => (float) $this->estimated_cost_usd,
            'actual_cost_usd' => (float) $this->actual_cost_usd,
            'interval_seconds' => $this->interval_seconds,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
