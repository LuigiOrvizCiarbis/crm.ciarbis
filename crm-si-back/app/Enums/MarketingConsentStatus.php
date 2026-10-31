<?php

namespace App\Enums;

enum MarketingConsentStatus: string
{
    case Granted = 'granted';
    case Denied = 'denied';
}
