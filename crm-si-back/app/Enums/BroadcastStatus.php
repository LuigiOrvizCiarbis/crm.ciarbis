<?php

namespace App\Enums;

enum BroadcastStatus: string
{
    case Scheduled = 'scheduled';
    case Processing = 'processing';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
