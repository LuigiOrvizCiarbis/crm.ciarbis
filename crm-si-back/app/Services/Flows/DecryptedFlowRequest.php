<?php

namespace App\Services\Flows;

final readonly class DecryptedFlowRequest
{
    public function __construct(
        public array $payload,
        public string $aesKey,
        public string $iv,
    ) {}
}
