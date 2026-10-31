<?php

namespace App\Services\Scheduling;

/**
 * No se pudo consultar la agenda. El código distingue la causa para que la
 * API de aprobación responda distinto a cada una.
 */
class AppointmentAvailabilityException extends \RuntimeException
{
    /** El vendedor no tiene Google Calendar conectado (o necesita reautorizar). */
    public const NOT_CONNECTED = 1;

    /** Error o timeout de la API de Google. */
    public const GOOGLE_ERROR = 2;

    /** Otra reserva del mismo vendedor tiene tomado el lock. */
    public const BUSY = 3;
}
