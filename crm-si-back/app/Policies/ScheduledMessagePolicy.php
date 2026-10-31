<?php

namespace App\Policies;

use App\Models\ScheduledMessage;
use App\Models\User;

class ScheduledMessagePolicy
{
    public function __construct(private ConversationPolicy $conversations) {}

    public function update(User $user, ScheduledMessage $scheduledMessage): bool
    {
        return $this->canManage($user, $scheduledMessage);
    }

    public function delete(User $user, ScheduledMessage $scheduledMessage): bool
    {
        return $this->canManage($user, $scheduledMessage);
    }

    /**
     * Sólo quien puede escribir en la conversación toca sus programados, y
     * entre ellos el autor o quien puede editar mensajes ajenos
     * (messages.update_any, el mismo criterio que MessagePolicy).
     */
    private function canManage(User $user, ScheduledMessage $scheduledMessage): bool
    {
        if ((int) $scheduledMessage->tenant_id !== (int) $user->tenant_id) {
            return false;
        }

        $conversation = $scheduledMessage->conversation;
        if ($conversation === null || ! $this->conversations->sendMessage($user, $conversation)) {
            return false;
        }

        return (int) $scheduledMessage->user_id === (int) $user->id
            || $user->can('messages.update_any');
    }
}
