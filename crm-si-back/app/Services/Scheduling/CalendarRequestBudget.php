<?php

namespace App\Services\Scheduling;

use Closure;
use Psr\Http\Message\RequestInterface;

/** One accumulated HTTP deadline per calendar operation, shorter than the owner lease. */
class CalendarRequestBudget
{
    private readonly Closure $clock;

    private readonly float $deadline;

    public function __construct(?float $seconds = null, ?callable $clock = null)
    {
        $this->clock = Closure::fromCallable($clock ?? static fn (): float => microtime(true));
        $this->deadline = ($this->clock)() + ($seconds ?? 45.0);
    }

    public function remaining(): float
    {
        return max(0.0, $this->deadline - ($this->clock)());
    }

    public function assertAvailable(): void
    {
        if ($this->remaining() <= 0) {
            throw new AppointmentAvailabilityException('Se agotó el tiempo de consulta de la agenda', AppointmentAvailabilityException::GOOGLE_ERROR);
        }
    }

    public function middleware(): callable
    {
        return fn (callable $handler): Closure => function (RequestInterface $request, array $options) use ($handler) {
            $remaining = $this->remaining();
            if ($remaining <= 0) {
                $this->assertAvailable();
            }
            $timeout = (float) ($options['timeout'] ?? 0);
            $options['timeout'] = min($timeout > 0 ? $timeout : 20.0, $remaining);
            $connectTimeout = (float) ($options['connect_timeout'] ?? 0);
            $options['connect_timeout'] = min($connectTimeout > 0 ? $connectTimeout : $options['timeout'], $options['timeout']);

            return $handler($request, $options);
        };
    }
}
