<?php

namespace App\Services\Observability\Langfuse;

use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Convierte el uso de tokens de cada proveedor al formato de Langfuse:
 * buckets exclusivos (cada token cuenta en una sola clave). Langfuse suma
 * las claves para el total y el costo; un bucket que incluya a otro se
 * cobraría dos veces.
 */
final class UsageDetails
{
    /**
     * Usage del SDK de Anthropic. Anthropic ya reporta input sin la caché.
     *
     * @return array<string, int>|null
     */
    public static function fromAnthropic(?object $usage): ?array
    {
        if ($usage === null) {
            return null;
        }

        return self::buckets([
            'input' => $usage->inputTokens ?? null,
            'output' => $usage->outputTokens ?? null,
            'input_cache_read' => $usage->cacheReadInputTokens ?? null,
            'input_cache_creation' => $usage->cacheCreationInputTokens ?? null,
        ]);
    }

    /**
     * Usage del JSON crudo de la API de Anthropic (decide() no usa el SDK).
     *
     * @param  array<string, mixed>|null  $usage
     * @return array<string, int>|null
     */
    public static function fromAnthropicArray(?array $usage): ?array
    {
        if (! isset($usage['input_tokens'])) {
            return null;
        }

        return self::buckets([
            'input' => $usage['input_tokens'],
            'output' => $usage['output_tokens'] ?? null,
            'input_cache_read' => $usage['cache_read_input_tokens'] ?? null,
            'input_cache_creation' => $usage['cache_creation_input_tokens'] ?? null,
        ]);
    }

    /**
     * Usage del SDK de OpenAI: prompt y completion incluyen caché y
     * razonamiento, que se restan a su bucket.
     *
     * @return array<string, int>|null
     */
    public static function fromOpenAi(?object $usage): ?array
    {
        if ($usage === null) {
            return null;
        }

        $cached = (int) ($usage->promptTokensDetails?->cachedTokens ?? 0);
        $reasoning = (int) ($usage->completionTokensDetails?->reasoningTokens ?? 0);

        return self::buckets([
            'input' => (int) $usage->promptTokens - $cached,
            'input_cached_tokens' => $cached,
            'output' => (int) ($usage->completionTokens ?? 0) - $reasoning,
            'output_reasoning_tokens' => $reasoning,
        ]);
    }

    /**
     * Usage de laravel/ai: input incluye caché y output incluye razonamiento.
     *
     * @return array<string, int>
     */
    public static function fromLaravelAi(TextUsage $usage): array
    {
        $cacheRead = (int) ($usage->cacheReadInputTokens ?? 0);
        $cacheWrite = (int) ($usage->cacheWriteInputTokens ?? 0);
        $reasoning = (int) ($usage->reasoningTokens ?? 0);

        return self::buckets([
            'input' => $usage->inputTokens - $cacheRead - $cacheWrite,
            'input_cache_read' => $cacheRead,
            'input_cache_creation' => $cacheWrite,
            'output' => $usage->outputTokens - $reasoning,
            'output_reasoning_tokens' => $reasoning,
        ]) ?? [];
    }

    /** @return array<string, int>|null */
    public static function fromTokens(?int $input, ?int $output): ?array
    {
        if ($input === null && $output === null) {
            return null;
        }

        return self::buckets(['input' => $input, 'output' => $output]);
    }

    /**
     * input y output siempre van (aunque sean 0); los detalles en 0 o null
     * se omiten.
     *
     * @param  array<string, int|null>  $values
     * @return array<string, int>|null
     */
    private static function buckets(array $values): ?array
    {
        $buckets = [];
        foreach ($values as $key => $value) {
            if (in_array($key, ['input', 'output'], true)) {
                $buckets[$key] = max(0, (int) $value);
            } elseif ($value !== null && (int) $value > 0) {
                $buckets[$key] = (int) $value;
            }
        }

        return $buckets === [] ? null : $buckets;
    }
}
