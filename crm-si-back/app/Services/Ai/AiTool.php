<?php

namespace App\Services\Ai;

/**
 * Herramienta que el modelo puede invocar, en formato neutral: cada driver la
 * traduce al formato de su API (Anthropic `input_schema`, OpenAI `function`).
 */
final class AiTool
{
    /**
     * @param  array<string, mixed>  $parameters  JSON Schema de tipo `object` con los argumentos.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
    ) {}
}
