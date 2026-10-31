<?php

namespace App\Services\Ai;

/**
 * Traduce el error HTTP de un proveedor a un código legible para la UI.
 *
 * Vive aparte porque el mismo mapeo lo necesitan resultados distintos
 * (verificación de key y extracción de documentos), y cada uno arma su propio
 * DTO con el código y el mensaje que devuelve este helper.
 */
class AiErrorMapper
{
    /**
     * @return array{code: string, message: string}
     */
    public static function fromStatus(int $status, ?string $message): array
    {
        $message ??= '';

        $code = match (true) {
            $status === 401, $status === 403 => 'invalid_key',
            $status === 429 => 'rate_limit',
            $status === 400 && str_contains(strtolower($message), 'credit balance') => 'no_credit',
            default => 'unknown',
        };

        return ['code' => $code, 'message' => $message];
    }
}
