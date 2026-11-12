<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latestConversation = $this->relationLoaded('conversations') ? $this->conversations->first() : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'source' => $this->source,
            'branch_id' => $this->branch_id,
            'avatar_url' => $this->avatar_url,
            'avatar_source' => $this->avatar_source,
            'custom_data' => $this->custom_data ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'tags' => $this->whenLoaded('tags'),
            'pipeline_stage' => $this->when(
                $latestConversation && $latestConversation->relationLoaded('pipelineStage') && $latestConversation->pipelineStage,
                fn () => [
                    'id' => $latestConversation->pipelineStage->id,
                    'name' => $latestConversation->pipelineStage->name,
                ]
            ),
            'assigned_user' => $this->when(
                $latestConversation && $latestConversation->relationLoaded('assignedUser') && $latestConversation->assignedUser,
                fn () => [
                    'id' => $latestConversation->assignedUser->id,
                    'name' => $latestConversation->assignedUser->name,
                    'email' => $latestConversation->assignedUser->email,
                ]
            ),
            'conversations' => $this->whenLoaded('conversations', fn () => $this->conversations->map(fn ($conversation) => [
                'id' => $conversation->id,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'last_message_content' => $conversation->last_message_content,
            ])),
            'ad_referrals' => AdReferralResource::collection($this->whenLoaded('adReferrals')),
        ];
    }
}
