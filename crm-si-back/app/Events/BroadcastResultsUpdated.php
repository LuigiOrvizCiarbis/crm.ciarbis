<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class BroadcastResultsUpdated implements ShouldBroadcastNow
{
    public function __construct(public int $campaignId, public int $recipientId) {}

    public function broadcastOn(): array { return [new PrivateChannel('broadcasts.'.$this->campaignId)]; }
    public function broadcastAs(): string { return 'broadcast.results.updated'; }
    public function broadcastWith(): array { return ['campaign_id' => $this->campaignId, 'recipient_id' => $this->recipientId]; }
}
