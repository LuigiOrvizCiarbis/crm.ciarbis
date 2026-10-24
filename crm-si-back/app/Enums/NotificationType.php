<?php

namespace App\Enums;

enum NotificationType: string
{
    case MESSAGE_RECEIVED = 'message.received';
    case CONVERSATION_ASSIGNED = 'conversation.assigned';
    case CONVERSATION_UNASSIGNED = 'conversation.unassigned';
    case HANDOFF_REQUESTED = 'handoff.requested';
    case TASK_ASSIGNED = 'task.assigned';
    case IMPORT_COMPLETED = 'import.completed';
    case IMPORT_FAILED = 'import.failed';
    case CHANNEL_TOKEN_INVALID = 'channel.token_invalid';
    case TRIAL_EXPIRING = 'trial.expiring';

    /**
     * Obtener todos los valores posibles
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
