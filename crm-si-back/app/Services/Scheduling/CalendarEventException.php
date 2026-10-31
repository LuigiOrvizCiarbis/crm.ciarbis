<?php

namespace App\Services\Scheduling;

class CalendarEventException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
