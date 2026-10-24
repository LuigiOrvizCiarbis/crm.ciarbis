<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Sincroniza el estado leído entre pestañas/dispositivos del mismo usuario. */
class UserNotificationsRead implements ShouldBroadcastNow
{
    /**
     * @param  list<int>|null  $ids  null = todas las del workspace
     */
    public function __construct(
        public int $userId,
        public int $tenantId,
        public ?array $ids,
        public int $unreadCount,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notifications.read';
    }

    public function broadcastWith(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'ids' => $this->ids,
            'unread_count' => $this->unreadCount,
        ];
    }
}
