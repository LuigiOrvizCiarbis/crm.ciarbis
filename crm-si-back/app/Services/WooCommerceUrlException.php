<?php

namespace App\Services;

use Throwable;

/**
 * Se lanza cuando la store_url de WooCommerce no es una URL pública válida
 * (esquema no http/https, puerto no estándar, o resuelve a una IP privada/
 * loopback/link-local). Protege contra SSRF: la URL la controla el tenant.
 * $retryable marca los rechazos que pueden ser transitorios (DNS que no
 * resolvió); el resto no cambia entre reintentos.
 */
class WooCommerceUrlException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
