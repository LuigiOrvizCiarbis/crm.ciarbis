<?php

namespace App\Services\Ai;

/**
 * Resultado de una vuelta de autoreply: o un texto para mandarle al cliente, o
 * un pedido de derivación a humano. En la derivación, $reply es el texto que el
 * modelo escribió junto a la tool (si escribió alguno) y se usa como aviso al
 * cliente en lugar del mensaje fijo.
 */
final class AiReplyDecision
{
    private function __construct(public readonly ?string $reply, public readonly ?array $handoff) {}

    public static function reply(?string $reply): self
    {
        return new self($reply, null);
    }

    public static function handoff(array $data, ?string $message = null): self
    {
        $message = $message !== null ? trim($message) : null;

        return new self($message !== '' ? $message : null, $data);
    }

    public function requestsHandoff(): bool
    {
        return $this->handoff !== null;
    }
}
