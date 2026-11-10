<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Exceptions\TranslationException;
use App\Models\AiConfig;
use App\Models\Message;
use App\Models\MessageTranslation;
use App\Services\Ai\TenantAiProviderFactory;

class MessageTranslationService
{
    public const SUPPORTED_LANGUAGES = ['es', 'en', 'pt', 'fr', 'it', 'de', 'zh'];

    private const LANGUAGE_NAMES = [
        'es' => 'Spanish',
        'en' => 'English',
        'pt' => 'Portuguese',
        'fr' => 'French',
        'it' => 'Italian',
        'de' => 'German',
        'zh' => 'Simplified Chinese',
    ];

    public function __construct(private TenantAiProviderFactory $providers) {}

    /**
     * @return array{translation: MessageTranslation, cached: bool}
     */
    public function translateMessage(Message $message, string $targetLanguage): array
    {
        $this->assertSupportedLanguage($targetLanguage);

        if ($message->trashed()) {
            throw new TranslationException('No se puede traducir un mensaje eliminado.', 'message_deleted', 422);
        }

        $content = $this->messageContent($message);
        if ($content === null) {
            throw new TranslationException('Este mensaje no contiene texto traducible.', 'content_not_translatable', 422);
        }

        $sourceHash = hash('sha256', $content);
        $existing = $message->translations()
            ->where('target_language', $targetLanguage)
            ->first();

        if ($existing && hash_equals($existing->source_hash, $sourceHash)) {
            return ['translation' => $existing, 'cached' => true];
        }

        $translated = $this->translateText($message->tenant_id, $content, $targetLanguage);

        $translation = $message->translations()->updateOrCreate(
            ['target_language' => $targetLanguage],
            [
                'tenant_id' => $message->tenant_id,
                'translated_content' => $translated,
                'source_hash' => $sourceHash,
            ],
        );

        return ['translation' => $translation, 'cached' => false];
    }

    public function translateDraft(int $tenantId, string $content, string $targetLanguage): string
    {
        $this->assertSupportedLanguage($targetLanguage);

        return $this->translateText($tenantId, trim($content), $targetLanguage);
    }

    private function assertSupportedLanguage(string $targetLanguage): void
    {
        if (! in_array($targetLanguage, self::SUPPORTED_LANGUAGES, true)) {
            throw new TranslationException('El idioma seleccionado no está disponible.', 'invalid_language', 422);
        }
    }

    private function translateText(int $tenantId, string $content, string $targetLanguage): string
    {
        if ($content === '') {
            throw new TranslationException('Ingresá un texto para traducir.', 'content_not_translatable', 422);
        }

        $config = AiConfig::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();
        $provider = $config ? $this->providers->make($config) : null;

        if (! $config || ! $provider) {
            throw new TranslationException(
                'Configurá una API key de IA para usar la traducción.',
                'ai_not_configured',
                422,
            );
        }

        $model = (string) config("services.ai.translation_models.{$config->provider->value}");
        if ($model === '') {
            throw new TranslationException('No hay un modelo de traducción configurado.', 'model_not_configured', 422);
        }

        $targetName = self::LANGUAGE_NAMES[$targetLanguage];
        $systemPrompt = "Translate the user's message to {$targetName}. "
            .'Detect the source language automatically. Preserve meaning, tone, names, numbers, URLs, emojis and line breaks. '
            .'Do not answer the message, explain the translation, add labels, or wrap the result in quotes. Return only the translated text.';

        $translated = $provider->translate($content, $systemPrompt, $model);
        if (! is_string($translated) || trim($translated) === '') {
            throw new TranslationException(
                'No se pudo traducir el mensaje. Intentá nuevamente.',
                'translation_failed',
                502,
            );
        }

        return trim($translated);
    }

    private function messageContent(Message $message): ?string
    {
        if (! in_array($message->message_type, [MessageType::Text, MessageType::Image], true)) {
            return null;
        }

        $content = trim((string) $message->content);
        if ($content === '') {
            return null;
        }

        return $this->templateBody($content) ?: $content;
    }

    private function templateBody(string $content): ?string
    {
        if (str_starts_with($content, '📋') && str_contains($content, "\n")) {
            return trim((string) str($content)->after("\n"));
        }

        $withoutIcon = trim((string) preg_replace('/^[^A-Za-z0-9]+/u', '', $content));
        if (preg_match('/^Template:\s*[^\(]+\s*\(([\s\S]+)\)?$/i', $withoutIcon, $matches)) {
            return trim($matches[1], " \t\n\r\0\x0B)");
        }

        return null;
    }
}
