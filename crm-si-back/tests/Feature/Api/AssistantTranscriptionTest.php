<?php

namespace Tests\Feature\Api;

use App\Enums\AiProvider;
use App\Models\AiConfig;
use App\Models\AssistantUsage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AudioDuration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssistantTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ai.assistant.source' => 'tenant',
            'services.ai.assistant.transcription.platform' => [
                'driver' => 'groq',
                'key' => 'gsk-test',
                'url' => null,
                'model' => 'whisper-large-v3-turbo',
            ],
        ]);

        $this->tenant = $this->createTenantWithRoles();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->member = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->member->assignRole('Member');
        Sanctum::actingAs($this->member);
    }

    public function test_openai_workspace_transcribes_with_its_own_key_and_records_the_reported_seconds(): void
    {
        $this->aiConfig(AiProvider::OPENAI);
        Transcription::fake([$this->response('Recordame llamar a Jorge mañana', audioSeconds: 12.5)]);
        $this->mock(AudioDuration::class)->shouldNotReceive('seconds');

        $this->transcribe()
            ->assertOk()
            ->assertJsonPath('data.text', 'Recordame llamar a Jorge mañana');

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => $prompt->language === 'es'
            && $prompt->model === 'gpt-4o-mini-transcribe');
        $this->assertEquals(12.5, $this->audioSecondsUsed());
    }

    public function test_claude_workspace_falls_back_to_the_platform_transcription_key(): void
    {
        $this->aiConfig(AiProvider::CLAUDE);
        Transcription::fake(['Hola']);

        $this->transcribe()->assertOk()->assertJsonPath('data.text', 'Hola');

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => $prompt->model === 'whisper-large-v3-turbo');
    }

    public function test_claude_workspace_without_platform_key_explains_that_dictation_is_unavailable(): void
    {
        $this->aiConfig(AiProvider::CLAUDE);
        config(['services.ai.assistant.transcription.platform.key' => null]);
        Transcription::fake();

        $this->transcribe()->assertStatus(503)->assertJsonPath('code', 'transcription_unavailable');
        Transcription::assertNothingGenerated();
    }

    public function test_openai_compatible_platform_driver_without_url_is_unavailable(): void
    {
        $this->aiConfig(AiProvider::CLAUDE);
        config(['services.ai.assistant.transcription.platform' => [
            'driver' => 'openai-compatible',
            'key' => 'sk-test',
            'url' => null,
            'model' => 'whisper-1',
        ]]);
        Transcription::fake();

        $this->transcribe()->assertStatus(503)->assertJsonPath('code', 'transcription_unavailable');
        Transcription::assertNothingGenerated();
    }

    public function test_provider_failure_is_reported_without_recording_usage(): void
    {
        $this->aiConfig(AiProvider::OPENAI);
        Transcription::fake(fn () => throw new \RuntimeException('Provider down'));

        $this->transcribe()->assertStatus(502)->assertJsonPath('code', 'transcription_failed');
        $this->assertFalse(AssistantUsage::query()->where('tenant_id', $this->tenant->id)->exists());
    }

    public function test_user_without_assistant_permission_is_forbidden(): void
    {
        $this->aiConfig(AiProvider::OPENAI);
        Role::query()->where('tenant_id', $this->tenant->id)->where('name', 'Member')->firstOrFail()
            ->revokePermissionTo('assistant.use');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Transcription::fake();

        $this->transcribe()->assertForbidden();
        Transcription::assertNothingGenerated();
    }

    public function test_oversized_audio_is_rejected(): void
    {
        $this->aiConfig(AiProvider::OPENAI);
        config(['services.ai.assistant.transcription.max_kb' => 1]);
        Transcription::fake();

        $this->post('/api/assistant/transcriptions', [
            'audio' => UploadedFile::fake()->createWithContent('nota.webm', $this->webmBytes().str_repeat("\0", 2048)),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('audio');
        Transcription::assertNothingGenerated();
    }

    public function test_platform_dictation_stops_at_the_daily_audio_limit(): void
    {
        $this->aiConfig(AiProvider::CLAUDE);
        config(['services.ai.assistant.transcription.daily_audio_seconds_limit_per_tenant' => 60]);
        $this->usage(audioSeconds: 60);
        Transcription::fake();

        $this->transcribe()->assertStatus(429)->assertJsonPath('code', 'transcription_daily_limit');
        Transcription::assertNothingGenerated();
    }

    public function test_chat_tokens_paid_with_the_workspace_key_do_not_block_dictation(): void
    {
        // Un workspace con Claude propio que ya gastó muchos tokens de chat
        // (pagados por él) sigue pudiendo dictar con la key nuestra.
        $this->aiConfig(AiProvider::CLAUDE);
        config(['services.ai.assistant.daily_token_limit_per_tenant' => 1000]);
        $this->usage(inputTokens: 50000);
        Transcription::fake(['Hola']);

        $this->transcribe()->assertOk()->assertJsonPath('data.text', 'Hola');
    }

    public function test_workspace_key_dictation_has_no_audio_limit(): void
    {
        $this->aiConfig(AiProvider::OPENAI);
        config(['services.ai.assistant.transcription.daily_audio_seconds_limit_per_tenant' => 60]);
        $this->usage(audioSeconds: 600);
        Transcription::fake(['Hola']);

        $this->transcribe()->assertOk();
    }

    public function test_platform_dictation_measures_the_audio_when_the_provider_does_not_report_it(): void
    {
        $this->aiConfig(AiProvider::CLAUDE);
        // El fake no informa segundos de audio, como un proveedor que no los reporta.
        Transcription::fake(['Hola']);
        $this->mock(AudioDuration::class)->shouldReceive('seconds')->once()->andReturn(42.5);

        $this->transcribe()->assertOk();

        $this->assertEquals(42.5, $this->audioSecondsUsed());
    }

    public function test_workspace_without_ai_key_is_asked_to_configure_it(): void
    {
        Transcription::fake();

        $this->transcribe()->assertStatus(422)->assertJsonPath('code', 'ai_not_configured');
        Transcription::assertNothingGenerated();
    }

    public function test_silence_is_reported_instead_of_returning_empty_text(): void
    {
        $this->aiConfig(AiProvider::OPENAI);
        Transcription::fake(['   ']);

        $this->transcribe()->assertStatus(422)->assertJsonPath('code', 'transcription_empty');
    }

    public function test_non_audio_files_are_rejected(): void
    {
        $this->aiConfig(AiProvider::OPENAI);
        Transcription::fake();

        $this->post('/api/assistant/transcriptions', [
            'audio' => UploadedFile::fake()->create('notas.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('audio');
        Transcription::assertNothingGenerated();
    }

    private function usage(int $inputTokens = 0, float $audioSeconds = 0): void
    {
        AssistantUsage::query()->create([
            'tenant_id' => $this->tenant->id,
            'date' => today()->toDateString(),
            'input_tokens' => $inputTokens,
            'output_tokens' => 0,
            'audio_seconds' => $audioSeconds,
        ]);
    }

    private function audioSecondsUsed(): float
    {
        return (float) AssistantUsage::query()->where('tenant_id', $this->tenant->id)->value('audio_seconds');
    }

    /** Respuesta de un proveedor que sí informa la duración del audio. */
    private function response(string $text, float $audioSeconds): TranscriptionResponse
    {
        return new TranscriptionResponse($text, new Collection, new TranscriptionUsage(audioSeconds: $audioSeconds), new Meta);
    }

    private function aiConfig(AiProvider $provider): void
    {
        AiConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => $provider,
            'enabled' => false,
        ])->setEncryptedApiKey('sk-test');
    }

    private function transcribe()
    {
        return $this->post('/api/assistant/transcriptions', [
            'audio' => UploadedFile::fake()->createWithContent('nota.webm', $this->webmBytes()),
        ], ['Accept' => 'application/json']);
    }

    /** Cabecera EBML mínima: alcanza para que el detector de tipo la vea como webm. */
    private function webmBytes(): string
    {
        return hex2bin('1a45dfa3a34286810142f7810142f2810442f381084282847765626d42878104428581021853806701000000000000');
    }
}
