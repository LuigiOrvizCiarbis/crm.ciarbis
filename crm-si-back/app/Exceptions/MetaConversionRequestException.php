<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Fallo de una llamada a la Conversions API de Meta, ya clasificado: los
 * reintentables (conexión, 429, 5xx) los vuelve a intentar la cola; el resto
 * deja el evento en failed.
 */
class MetaConversionRequestException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(string $message, public readonly bool $retryable, public readonly ?array $response = null)
    {
        parent::__construct($message);
    }
}
