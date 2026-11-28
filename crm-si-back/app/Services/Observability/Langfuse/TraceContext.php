<?php

namespace App\Services\Observability\Langfuse;

/**
 * Contexto compartido por todas las observaciones de un trace. Langfuse v4
 * filtra por observación, así que estos datos se copian en cada span.
 */
final readonly class TraceContext
{
    /**
     * @param  array<string, string|int|bool|null>  $metadata
     * @param  list<string>  $tags
     * @param  string|null  $tenantName  Se exporta como tag "tenant:{nombre}" para filtrar y armar dashboards por cliente.
     */
    public function __construct(
        public ?string $sessionId = null,
        public ?string $userId = null,
        public array $metadata = [],
        public array $tags = [],
        // int|string: según el driver la columna llega como string; un TypeError acá frenaría la respuesta.
        public int|string|null $tenantId = null,
        public ?string $tenantName = null,
    ) {}
}
