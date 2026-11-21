<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error del asistente interno que se le muestra al usuario tal cual, con un
 * código estable para que el front decida qué ofrecer (p. ej. configurar IA).
 */
class AssistantException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
