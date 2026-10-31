<?php

namespace App\Events;

use App\Models\ManualAiDraft;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ManualAiDraftUpdated implements ShouldBroadcastNow
{
    public function __construct(public ManualAiDraft $draft) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->draft->user_id)];
    }

    public function broadcastAs(): string { return 'manual-ai-draft.updated'; }

    public function broadcastWith(): array
    {
        return ['draft' => $this->draft->payload()];
    }
}
