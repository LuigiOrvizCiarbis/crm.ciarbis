<?php

namespace App\Services\Scheduling;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class CalendarOwnerLock
{
    /**
     * @template T
     *
     * @param callable(CalendarRequestBudget): T $operation
     * @return T
     */
    public function run(int $ownerId, callable $operation): mixed
    {
        try {
            return Cache::lock("ai-booking:owner:{$ownerId}", 60)
                ->block(10, fn () => $operation(app(CalendarRequestBudget::class)));
        } catch (LockTimeoutException $e) {
            throw new AppointmentAvailabilityException('Agenda ocupada', AppointmentAvailabilityException::BUSY, $e);
        }
    }
}
