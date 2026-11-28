<?php

namespace App\Services\Observability\Langfuse;

/**
 * Identifica una observación en Langfuse: los scores se cuelgan de ella.
 */
final readonly class TraceReference
{
    /**
     * @param  string  $traceId  32 caracteres hex (trace id de OpenTelemetry).
     * @param  string  $observationId  16 caracteres hex (span id de OpenTelemetry).
     */
    public function __construct(
        public string $traceId,
        public string $observationId,
    ) {}
}
