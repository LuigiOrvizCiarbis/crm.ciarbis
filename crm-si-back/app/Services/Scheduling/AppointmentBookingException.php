<?php

namespace App\Services\Scheduling;

use App\Models\Task;

/**
 * La reserva o la decisión sobre un turno no se puede aplicar.
 *
 * $reason ∈ slot_unavailable | active_booking_exists | handoff |
 * already_decided | slot_conflict.
 */
class AppointmentBookingException extends \RuntimeException
{
    public const SLOT_UNAVAILABLE = 'slot_unavailable';

    public const ACTIVE_BOOKING_EXISTS = 'active_booking_exists';

    public const HANDOFF = 'handoff';

    public const ALREADY_DECIDED = 'already_decided';

    public const SLOT_CONFLICT = 'slot_conflict';

    public function __construct(
        public readonly string $reason,
        public readonly ?Task $existing = null,
    ) {
        parent::__construct($reason);
    }
}
