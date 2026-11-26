<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Meta rechazó un mensaje saliente. Conserva el código y el detalle del error
 * para que quien envía pueda distinguir causas (por ejemplo, un catálogo sin
 * productos habilitados) sin interpretar el texto del cuerpo.
 */
class WhatsAppMessageRejectedException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $metaCode = null, public readonly ?string $details = null)
    {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $code = $response->json('error.code');

        return new self(
            'Meta rechazó el mensaje: '.$response->body(),
            is_numeric($code) ? (int) $code : null,
            is_string($details = $response->json('error.error_data.details')) ? $details : null,
        );
    }
}
