<?php

namespace App\Services\Ai;

/**
 * Resultado de una vuelta de autoreply: o un texto para mandarle al cliente, o
 * un pedido de derivación a humano. En la derivación, $reply es el aviso al
 * cliente: el customer_message de la tool, o si falta el texto que el modelo
 * escribió aparte. Sin ninguno de los dos se usa el mensaje fijo.
 */
final class AiReplyDecision
{
    private function __construct(public readonly ?string $reply, public readonly ?array $handoff) {}

    public static function reply(?string $reply): self
    {
        return new self($reply, null);
    }

    public static function handoff(array $data, ?string $text = null): self
    {
        return new self(self::nonBlank($data['customer_message'] ?? null) ?? self::nonBlank($text), $data);
    }

    private static function nonBlank(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    public function requestsHandoff(): bool
    {
        return $this->handoff !== null;
    }
}
