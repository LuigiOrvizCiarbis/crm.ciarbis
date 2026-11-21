<?php

namespace App\Services\Ai\Assistant;

use App\Enums\AiProvider;
use App\Exceptions\AssistantException;
use App\Models\AiConfig;

/**
 * Elige con qué proveedor responde el asistente interno, según
 * services.ai.assistant.source: la key BYOK del workspace o la de la
 * plataforma (incluido un servidor Ollama).
 */
class AssistantProviderResolver
{
    private const PLATFORM_DRIVERS = ['ollama', 'openai', 'anthropic', 'openai-compatible'];

    private const DRIVERS_WITH_URL = ['ollama', 'openai-compatible'];

    public function resolve(int $tenantId): ResolvedAssistantProvider
    {
        return config('services.ai.assistant.source') === 'platform'
            ? $this->platform()
            : $this->tenant($tenantId);
    }

    private function tenant(int $tenantId): ResolvedAssistantProvider
    {
        $config = AiConfig::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $key = $config?->getDecryptedApiKey();

        if (! $config || ! $key) {
            throw new AssistantException(
                'Configurá una API key de IA en Integraciones para usar el asistente.',
                'ai_not_configured',
                422,
            );
        }

        $driver = $config->provider === AiProvider::CLAUDE ? 'anthropic' : 'openai';

        return new ResolvedAssistantProvider(
            config: $this->withoutOpenAiRetention($driver, ['driver' => $driver, 'key' => $key]),
            model: (string) config("services.ai.assistant.tenant_models.{$config->provider->value}"),
            metered: false,
        );
    }

    private function platform(): ResolvedAssistantProvider
    {
        $settings = (array) config('services.ai.assistant.platform');
        $driver = (string) ($settings['driver'] ?? '');
        $key = $settings['key'] ?? null;
        $url = $settings['url'] ?? null;
        $model = (string) ($settings['model'] ?? '');

        $needsUrl = in_array($driver, self::DRIVERS_WITH_URL, true);
        $invalid = ! in_array($driver, self::PLATFORM_DRIVERS, true)
            || $model === ''
            || ($needsUrl ? blank($url) : blank($key));

        if ($invalid) {
            throw new AssistantException(
                'El asistente no está disponible en este momento.',
                'assistant_not_configured',
                503,
            );
        }

        $config = array_filter(
            ['driver' => $driver, 'key' => $key ?? '', 'url' => $url],
            fn ($value) => $value !== null,
        );

        return new ResolvedAssistantProvider(
            config: $this->withoutOpenAiRetention($driver, $config),
            model: $model,
            metered: true,
        );
    }

    /**
     * OpenAI guarda las respuestas por defecto; los datos del CRM no deben
     * quedar retenidos del lado del proveedor.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withoutOpenAiRetention(string $driver, array $config): array
    {
        return $driver === 'openai' ? [...$config, 'store' => false] : $config;
    }
}
