<?php

namespace App\Services\Scheduling;

class CalendarAvailabilityBlockException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
