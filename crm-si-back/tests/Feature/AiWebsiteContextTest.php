<?php

namespace Tests\Feature;

use App\Enums\AiProvider as AiProviderEnum;
use App\Enums\UserRole;
use App\Models\AiConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiExtractionResult;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiToolset;
use App\Services\Ai\AiVerificationResult;
use App\Services\Ai\TenantAiProviderFactory;
use App\Services\AiReplyService;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AiWebsiteContextTest extends TestCase
{
    use RefreshDatabase;

    // IP pública literal: PublicUrlGuard no necesita DNS real (ver PublicUrlGuardTest).
    private const SITE = 'https://93.184.216.34';

    private const SUMMARY = "## Horarios\n- Lunes a viernes 9 a 18";

    public function test_generates_draft_from_home_and_prioritized_internal_pages_without_saving(): void
    {
        [$user, $tenant] = $this->makeAdmin();
        $this->createAiConfig($tenant);
        $provider = $this->bindFakeProvider();
        Http::fake([
            self::SITE.'/' => $this->html(
                '<a href="/blog/post">Blog</a><a href="/contacto">Contacto</a>'
                .'<p>'.str_repeat('Vendemos repuestos para autos y motos en todo el país. ', 12).'</p>'
            ),
            self::SITE.'/contacto' => $this->html('<p>Lunes a viernes 9 a 18</p><a href="tel:+541145678900">Llamanos</a>'),
            self::SITE.'/blog/post' => $this->html('<p>Novedades del blog.</p>'),
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/ai-config/website-context', ['url' => self::SITE.'/'])
            ->assertOk()
            ->assertJsonPath('data.website_context', self::SUMMARY)
            ->assertJsonPath('data.website_url', self::SITE.'/')
            ->assertJsonPath('data.pages_read', [self::SITE.'/', self::SITE.'/contacto', self::SITE.'/blog/post']);

        $this->assertStringContainsString('Lunes a viernes 9 a 18', $provider->lastContent);
        $this->assertStringContainsString('tel:+541145678900', $provider->lastContent);
        $this->assertNull(AiConfig::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('website_context'));
    }

    public function test_rejects_private_urls(): void
    {
        [$user, $tenant] = $this->makeAdmin();
        $this->createAiConfig($tenant);
        $this->bindFakeProvider();
        Sanctum::actingAs($user);

        $this->postJson('/api/ai-config/website-context', ['url' => 'http://127.0.0.1/'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'url_rejected');
    }

    public function test_rejects_sites_without_enough_text(): void
    {
        [$user, $tenant] = $this->makeAdmin();
        $this->createAiConfig($tenant);
        $provider = $this->bindFakeProvider();
        Http::fake([self::SITE.'/' => $this->html('<div id="root"></div><p>Cargando…</p>')]);
        Sanctum::actingAs($user);

        $this->postJson('/api/ai-config/website-context', ['url' => self::SITE.'/'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'content_insufficient');
        $this->assertNull($provider->lastContent);
    }

    public function test_requires_a_saved_api_key(): void
    {
        [$user, $tenant] = $this->makeAdmin();
        AiConfig::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'provider' => AiProviderEnum::CLAUDE, 'enabled' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/ai-config/website-context', ['url' => self::SITE.'/'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_not_configured');
    }

    public function test_non_admin_cannot_generate(): void
    {
        [, $tenant] = $this->makeAdmin();
        $member = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE]);
        Sanctum::actingAs($member);

        $this->postJson('/api/ai-config/website-context', ['url' => self::SITE.'/'])->assertForbidden();
    }

    public function test_update_persists_website_fields_and_keeps_them_when_omitted(): void
    {
        [$user] = $this->makeAdmin();
        Sanctum::actingAs($user);

        $this->putJson('/api/ai-config', [
            'provider' => 'claude',
            'enabled' => true,
            'website_context' => str_repeat('a', 8001),
        ])->assertStatus(422)->assertJsonValidationErrors('website_context');

        $this->putJson('/api/ai-config', [
            'provider' => 'claude',
            'enabled' => true,
            'website_url' => self::SITE.'/',
            'website_context' => self::SUMMARY,
        ])->assertOk();

        $this->putJson('/api/ai-config', ['provider' => 'claude', 'enabled' => true])->assertOk();

        $this->getJson('/api/ai-config')
            ->assertOk()
            ->assertJsonPath('data.website_url', self::SITE.'/')
            ->assertJsonPath('data.website_context', self::SUMMARY);
    }

    public function test_system_prompt_places_website_context_after_tenant_instructions(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $method = new ReflectionMethod(AiReplyService::class, 'systemPrompt');

        $prompt = $method->invoke(
            app(AiReplyService::class),
            new AiConfig(['system_prompt' => 'Sos el asistente de Acme.', 'website_context' => '## Horarios']),
            $tenant->id,
            [],
        );

        $this->assertSame(
            "Sos el asistente de Acme.\n\n".AiReplyService::WEBSITE_CONTEXT_HEADER."\n## Horarios\n\n".AiReplyService::HISTORY_OVERRIDE_RULE,
            $prompt,
        );
    }

    private function html(string $body): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(
            "<html><head><title>Repuestos SA</title></head><body>{$body}</body></html>",
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    private function createAiConfig(Tenant $tenant): void
    {
        $config = AiConfig::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'provider' => AiProviderEnum::CLAUDE,
            'enabled' => true,
        ]);
        $config->setEncryptedApiKey('sk-test');
    }

    private function bindFakeProvider(): AiProvider
    {
        $provider = new class implements AiProvider
        {
            public ?string $lastContent = null;

            public function generate(array $messages, string $systemPrompt, string $model, ?AiToolset $toolset = null): ?string
            {
                return null;
            }

            public function translate(string $content, string $systemPrompt, string $model): ?string
            {
                return null;
            }

            public function summarize(string $content, string $systemPrompt, string $model, int $maxTokens): ?string
            {
                $this->lastContent = $content;

                return "## Horarios\n- Lunes a viernes 9 a 18";
            }

            public function listModels(): array
            {
                return [];
            }

            public function verify(string $systemPrompt, string $model): AiVerificationResult
            {
                return AiVerificationResult::ok();
            }

            public function extract(string $text, array $images, array $schema, string $systemPrompt, string $model): AiExtractionResult
            {
                return AiExtractionResult::failure('unsupported');
            }
        };

        $this->app->instance(TenantAiProviderFactory::class, new class($provider) extends TenantAiProviderFactory
        {
            public function __construct(private AiProvider $provider) {}

            public function make(AiConfig $config): ?AiProvider
            {
                return $this->provider;
            }
        });

        return $provider;
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function makeAdmin(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');

        return [$user, $tenant];
    }
}
