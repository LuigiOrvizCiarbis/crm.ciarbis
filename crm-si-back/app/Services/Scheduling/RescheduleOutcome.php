<?php

namespace App\Services\Scheduling;

/** Resultado de reprogramar el turno vigente de una conversación. */
enum RescheduleOutcome: string
{
    /** Turno confirmado movido; el evento de Google se actualiza. */
    case MOVED = 'moved';

    /** Turno pendiente movido; sigue esperando aprobación. */
    case MOVED_PENDING = 'moved_pending';

    /** Turno confirmado con un pedido de cambio que aprueba un asesor. */
    case REQUESTED = 'requested';

    /** Misma hora que la actual; si había un pedido de cambio, se retiró. */
    case UNCHANGED = 'unchanged';
}
