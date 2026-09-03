<?php

namespace App\Support;

/**
 * Resultado de PublicUrlGuard::fetch(): la URL final después de seguir
 * redirects (para resolver assets relativos contra ella) y el body, truncado
 * a MAX_BODY_BYTES.
 */
class PublicUrlFetchResult
{
    public function __construct(
        public readonly string $finalUrl,
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $body,
    ) {}
}
