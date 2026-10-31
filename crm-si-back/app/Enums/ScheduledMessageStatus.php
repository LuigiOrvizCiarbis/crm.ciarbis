<?php

namespace App\Enums;

enum ScheduledMessageStatus: string
{
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Estados que todavía no salieron hacia Meta: los que el usuario ve como
     * "programados" en la barra del chat.
     *
     * @return list<self>
     */
    public static function pending(): array
    {
        return [self::Scheduled, self::Queued, self::Sending];
    }

    /**
     * Un fallido se puede reprogramar: vuelve a Scheduled con fecha nueva.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Scheduled, self::Failed], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::Scheduled, self::Failed], true);
    }
}
