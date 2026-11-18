<?php

namespace App\Services\Flows;

final readonly class FlowEndpointResult
{
    public function __construct(
        public array $payload = [],
        public bool $invalidToken = false,
        public ?string $errorMessage = null,
    ) {}

    public static function payload(array $payload): self
    {
        return new self($payload);
    }

    public static function invalidToken(string $message): self
    {
        return new self(invalidToken: true, errorMessage: $message);
    }
}
