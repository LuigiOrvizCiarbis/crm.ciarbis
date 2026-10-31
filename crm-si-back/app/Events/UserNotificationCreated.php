<?php

namespace App\Events;

use App\Models\UserNotification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Nueva notificación o una agrupada que sumó un evento (mismo id, count++). */
class UserNotificationCreated implements ShouldBroadcastNow
{
    public function __construct(public UserNotification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return ['notification' => $this->notification->payload()];
    }
}
