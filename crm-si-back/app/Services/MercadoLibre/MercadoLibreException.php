<?php

namespace App\Services\MercadoLibre;

use RuntimeException;
use Throwable;

/**
 * Error de la API de Mercado Libre. $status es el HTTP de la respuesta (0 si
 * no hubo respuesta); $reauth indica que el vendedor tiene que volver a
 * conectar la cuenta.
 */
class MercadoLibreException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly bool $reauth = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** Sin respuesta, rate limit o error del lado de ML: vale la pena reintentar. */
    public function isTransient(): bool
    {
        return ! $this->reauth && ($this->status === 0 || $this->status === 429 || $this->status >= 500);
    }
}
