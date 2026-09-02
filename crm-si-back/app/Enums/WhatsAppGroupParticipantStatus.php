<?php

namespace App\Enums;

enum WhatsAppGroupParticipantStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Removed = 'removed';
    case PendingApproval = 'pending_approval';
    case Rejected = 'rejected';
}
