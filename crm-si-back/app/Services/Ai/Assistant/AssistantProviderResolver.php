<?php

namespace App\Services\Ai\Assistant;

use App\Enums\AiProvider;
use App\Exceptions\AssistantException;
use App\Models\AiConfig;

/**
 * Elige con qué proveedor responde el asistente interno, según
 * services.ai.assistant.source: la key BYOK del workspace o la de la
 * plataforma (incluido un servidor Ollama). También resuelve con qué se
 * transcribe el dictado por voz.
 */
class AssistantProviderResolver
{
    private const PLATFORM_DRIVERS = ['ollama', 'openai', 'anthropic', 'openai-compatible'];

    private const DRIVERS_WITH_URL = ['ollama', 'openai-compatible'];

    private const TRANSCRIPTION_DRIVERS = ['groq', 'openai', 'openai-compatible'];

    public function resolve(int $tenantId): ResolvedAssistantProvider
    {
        return config('services.ai.assistant.source') === 'platform'
            ? $this->platform()
            : $this->tenant($tenantId);
    }

    /**
     * Con source=tenant y key de OpenAI, se transcribe con la key del
     * workspace. Anthropic no transcribe audio y Ollama tampoco: en esos
     * casos (y con source=platform) se usa la key de transcripción nuestra.
     */
    public function resolveTranscription(int $tenantId): ResolvedAssistantProvider
    {
        if (config('services.ai.assistant.source') !== 'platform') {
            [$config, $key] = $this->tenantConfig($tenantId);

            if ($config->provider === AiProvider::OPENAI) {
                $model = (string) config('services.ai.assistant.transcription.openai_model');

                return new ResolvedAssistantProvider(
                    config: ['driver' => 'openai', 'key' => $key, 'models' => ['transcription' => ['default' => $model]]],
                    model: $model,
                    metered: false,
                );
            }
        }

        return $this->platformTranscription();
    }

    private function tenant(int $tenantId): ResolvedAssistantProvider
    {
        [$config, $key] = $this->tenantConfig($tenantId);
        $driver = $config->provider === AiProvider::CLAUDE ? 'anthropic' : 'openai';

        return new ResolvedAssistantProvider(
            config: $this->withoutOpenAiRetention($driver, ['driver' => $driver, 'key' => $key]),
            model: (string) config("services.ai.assistant.tenant_models.{$config->provider->value}"),
            metered: false,
        );
    }

    /**
     * @return array{0: AiConfig, 1: string}
     */
    private function tenantConfig(int $tenantId): array
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

        return [$config, $key];
    }

    private function platformTranscription(): ResolvedAssistantProvider
    {
        $settings = (array) config('services.ai.assistant.transcription.platform');
        $driver = (string) ($settings['driver'] ?? '');
        $key = $settings['key'] ?? null;
        $url = $settings['url'] ?? null;
        $model = (string) ($settings['model'] ?? '');

        $invalid = ! in_array($driver, self::TRANSCRIPTION_DRIVERS, true)
            || $model === ''
            || ($driver === 'openai-compatible' ? blank($url) : blank($key));

        if ($invalid) {
            throw new AssistantException(
                'El dictado por voz no está disponible en este momento.',
                'transcription_unavailable',
                503,
            );
        }

        return new ResolvedAssistantProvider(
            config: array_filter(
                ['driver' => $driver, 'key' => $key ?? '', 'url' => $url, 'models' => ['transcription' => ['default' => $model]]],
                fn ($value) => $value !== null,
            ),
            model: $model,
            metered: true,
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
