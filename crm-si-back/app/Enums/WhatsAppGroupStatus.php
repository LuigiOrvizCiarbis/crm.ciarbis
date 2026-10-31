<?php

namespace App\Enums;

enum WhatsAppGroupStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Deleted = 'deleted';
    case Failed = 'failed';
}
