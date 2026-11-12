<?php

namespace App\Services\Flows;

use App\Models\WhatsAppFlowSession;

final readonly class ParsedFlowResponse
{
    public function __construct(
        public ?WhatsAppFlowSession $session,
        public array $response,
        public string $content,
        public array $interactive,
    ) {}
}
