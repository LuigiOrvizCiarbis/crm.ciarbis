<?php

namespace App\Http\Resources;

use App\Models\AdReferral;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * No expone `raw`, `ctwa_clid` ni `dedupe_key`: son datos internos de atribución.
 *
 * @mixin AdReferral
 */
class AdReferralResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'source_type' => $this->source_type,
            'ad_id' => $this->ad_id,
            'ref' => $this->ref,
            'headline' => $this->headline,
            'body' => $this->body,
            'source_url' => $this->source_url,
            'media_type' => $this->media_type,
            'media_url' => $this->media_url,
            'conversation_id' => $this->conversation_id,
            'channel_id' => $this->channel_id,
            'referred_at' => $this->referred_at?->toIso8601String(),
        ];
    }
}
