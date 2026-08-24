<?php

namespace Tests\Feature;

use App\Enums\AiProvider as AiProviderEnum;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Exceptions\TranslationException;
use App\Models\AiConfig;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Ai\AiExtractionResult;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiVerificationResult;
use App\Services\Ai\TranslationProviderFactory;
use App\Services\MessageTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTranslationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_economic_model_and_reuses_cached_translations(): void
    {
        [$service, $provider, $tenant] = $this->translationSetup();
        $message = $this->message($tenant, 'Hola, ¿cómo estás?');

        $first = $service->translateMessage($message, 'en');
        $second = $service->translateMessage($message, 'en');

        $this->assertFalse($first['cached']);
        $this->assertTrue($second['cached']);
        $this->assertSame('Translated: Hola, ¿cómo estás?', $second['translation']->translated_content);
        $this->assertSame(1, $provider->calls);
        $this->assertSame('gpt-5-mini-test', $provider->lastModel);
    }

    public function test_it_regenerates_a_cached_translation_after_the_message_changes(): void
    {
        [$service, $provider, $tenant] = $this->translationSetup();
        $message = $this->message($tenant, 'Texto original');

        $service->translateMessage($message, 'en');
        $message->update(['content' => 'Texto editado']);
        $result = $service->translateMessage($message->fresh(), 'en');

        $this->assertFalse($result['cached']);
        $this->assertSame('Translated: Texto editado', $result['translation']->translated_content);
        $this->assertSame(2, $provider->calls);
        $this->assertDatabaseCount('message_translations', 1);
    }

    public function test_draft_translation_works_when_autoreply_is_disabled(): void
    {
        [$service, $provider, $tenant] = $this->translationSetup(enabled: false);

        $translated = $service->translateDraft($tenant->id, 'Buen día', 'pt');

        $this->assertSame('Translated: Buen día', $translated);
        $this->assertSame(1, $provider->calls);
    }

    public function test_it_rejects_translation_without_ai_configuration(): void
    {
        $tenant = Tenant::create(['name' => 'Sin IA']);
        $service = new MessageTranslationService(new TranslationProviderFactory);

        try {
            $service->translateDraft($tenant->id, 'Hola', 'en');
            $this->fail('Expected TranslationException was not thrown.');
        } catch (TranslationException $exception) {
            $this->assertSame('ai_not_configured', $exception->errorCode);
            $this->assertSame(422, $exception->status);
        }
    }

    public function test_it_supports_simplified_chinese_translations(): void
    {
        [$service, $provider, $tenant] = $this->translationSetup();

        $translation = $service->translateDraft($tenant->id, 'Hola', 'zh');

        $this->assertSame('Translated: Hola', $translation);
        $this->assertStringContainsString('Simplified Chinese', $provider->lastSystemPrompt);
    }

    /**
     * @return array{MessageTranslationService, object, Tenant}
     */
    private function translationSetup(bool $enabled = true): array
    {
        config(['services.ai.translation_models.openai' => 'gpt-5-mini-test']);

        $tenant = Tenant::create(['name' => 'Tenant traducciones']);
        $config = AiConfig::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'provider' => AiProviderEnum::OPENAI,
            'model' => 'gpt-5-premium-for-bot',
            'enabled' => $enabled,
        ]);
        $config->setEncryptedApiKey('test-key');

        $provider = new class implements AiProvider
        {
            public int $calls = 0;

            public ?string $lastModel = null;

            public ?string $lastSystemPrompt = null;

            public function generate(array $messages, string $systemPrompt, string $model): ?string
            {
                return null;
            }

            public function translate(string $content, string $systemPrompt, string $model): ?string
            {
                $this->calls++;
                $this->lastModel = $model;
                $this->lastSystemPrompt = $systemPrompt;

                return 'Translated: '.$content;
            }

            public function listModels(): array
            {
                return [];
            }

            public function verify(string $systemPrompt, string $model): AiVerificationResult
            {
                return AiVerificationResult::ok();
            }

            public function extract(string $text, array $schema, string $systemPrompt, string $model): AiExtractionResult
            {
                return AiExtractionResult::failure('unsupported');
            }
        };

        $factory = new class($provider) extends TranslationProviderFactory
        {
            public function __construct(private AiProvider $provider) {}

            public function make(AiConfig $config): ?AiProvider
            {
                return $this->provider;
            }
        };

        return [new MessageTranslationService($factory), $provider, $tenant];
    }

    private function message(Tenant $tenant, string $content): Message
    {
        return Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => null,
            'content' => $content,
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'sender_type' => SenderType::SYSTEM,
            'sender_id' => null,
        ]);
    }
}
