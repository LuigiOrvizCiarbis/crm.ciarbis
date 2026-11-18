<?php

namespace App\Services\Flows;

use App\Models\WhatsAppFlowSession;

/**
 * `answers` son las respuestas tal como las mandó el formulario (lo que se
 * guarda en la sesión y se mapea al contacto); `response` es la versión
 * legible para el chat y las automatizaciones, con cada grupo repetible
 * convertido en su lista de filas.
 */
final readonly class ParsedFlowResponse
{
    public function __construct(
        public ?WhatsAppFlowSession $session,
        public array $answers,
        public array $response,
        public string $content,
        public array $interactive,
    ) {}
}
