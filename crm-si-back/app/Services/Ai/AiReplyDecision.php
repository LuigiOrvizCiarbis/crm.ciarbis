<?php

namespace App\Services\Ai;

final class AiReplyDecision
{
    private function __construct(public readonly ?string $reply, public readonly ?array $handoff) {}

    public static function reply(?string $reply): self { return new self($reply, null); }
    public static function handoff(array $data): self { return new self(null, $data); }
    public function requestsHandoff(): bool { return $this->handoff !== null; }
}
