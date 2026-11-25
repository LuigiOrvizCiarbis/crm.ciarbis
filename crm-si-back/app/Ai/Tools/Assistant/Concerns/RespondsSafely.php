<?php

namespace App\Ai\Tools\Assistant\Concerns;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Respuesta de las herramientas del asistente: JSON compacto para el modelo y,
 * ante cualquier error, un texto neutro en vez de cortar la respuesta.
 */
trait RespondsSafely
{
    /**
     * @param  Closure(): (array<mixed>|string)  $query
     */
    protected function respond(Closure $query): string
    {
        try {
            $result = $query();

            return is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('Assistant tool failed', ['tool' => static::class, 'error' => $e->getMessage()]);

            return 'No se pudieron consultar los datos.';
        }
    }
}
