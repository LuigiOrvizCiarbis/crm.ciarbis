<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\InstagramConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstagramOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/admin/channels/instagram-auth';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.facebook.app_id', 'test-app-id');
        config()->set('services.facebook.app_secret', 'test-app-secret');
        config()->set('services.facebook.graph_version', 'v21.0');
    }

    public function test_single_page_connects_directly(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeMeta([
            ['page_id' => 'PAGE_1', 'name' => 'Acme', 'ig_id' => 'IG_1', 'username' => 'acme', 'token' => 'PAGE_TOKEN_1'],
        ]);

        $response = $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA', 'name' => 'Instagram']);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, InstagramConfig::count());
        $this->assertSame(1, Channel::where('tenant_id', $tenant->id)->count());

        $config = InstagramConfig::first();
        $this->assertSame('IG_1', $config->ig_user_id);
        $this->assertSame('PAGE_1', $config->page_id);
        $this->assertSame('acme', $config->username);
        $this->assertSame('PAGE_TOKEN_1', Crypt::decryptString($config->page_access_token));

        $channel = Channel::first();
        $this->assertSame(ChannelType::INSTAGRAM, $channel->type);
        $this->assertSame('IG_1', $channel->external_id);
        $this->assertSame('@acme', $channel->name);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains((string) $request->url(), '/IG_1/subscribed_apps')
            && $request['subscribed_fields'] === 'messages,comments');
    }

    public function test_multiple_pages_returns_selection_without_reexchanging_code(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeMeta([
            ['page_id' => 'PAGE_1', 'name' => 'Acme', 'ig_id' => 'IG_1', 'username' => 'acme', 'token' => 'PAGE_TOKEN_1'],
            ['page_id' => 'PAGE_2', 'name' => 'Beta', 'ig_id' => 'IG_2', 'username' => 'beta', 'token' => 'PAGE_TOKEN_2'],
        ]);

        $first = $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA']);

        $first->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('requires_page_selection', true)
            ->assertJsonCount(2, 'pages');

        $onboardingToken = $first->json('onboarding_token');
        $this->assertNotEmpty($onboardingToken);
        // Todavía no se creó nada: falta elegir página.
        $this->assertSame(0, InstagramConfig::count());

        $second = $this->postJson(self::ENDPOINT, [
            'onboarding_token' => $onboardingToken,
            'page_id' => 'PAGE_2',
        ]);

        $second->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, InstagramConfig::count());
        $config = InstagramConfig::first();
        $this->assertSame('IG_2', $config->ig_user_id);
        $this->assertSame('PAGE_TOKEN_2', Crypt::decryptString($config->page_access_token));
    }

    public function test_expired_onboarding_token_returns_410(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $response = $this->postJson(self::ENDPOINT, [
            'onboarding_token' => 'nonexistent-token',
            'page_id' => 'PAGE_1',
        ]);

        $response->assertStatus(410)->assertJsonPath('success', false);
        $this->assertSame(0, InstagramConfig::count());
    }

    public function test_no_linked_instagram_returns_422(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        // Página sin instagram_business_account.
        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'USER_TOKEN', 'token_type' => 'bearer'], 200);
            }
            if (str_contains($url, '/me/accounts')) {
                return Http::response(['data' => [['id' => 'PAGE_1', 'name' => 'Acme', 'access_token' => 'T']]], 200);
            }

            return Http::response(['error' => ['message' => "unmapped {$url}"]], 404);
        });

        $response = $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA']);

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_account_connected_by_another_tenant_returns_409(): void
    {
        [$tenantA, $userA] = $this->createTenantAndUser();
        [$tenantB, $userB] = $this->createTenantAndUser();

        $this->fakeMeta([
            ['page_id' => 'PAGE_1', 'name' => 'Acme', 'ig_id' => 'IG_1', 'username' => 'acme', 'token' => 'PAGE_TOKEN_A'],
        ]);

        Sanctum::actingAs($userA);
        $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA'])->assertOk();

        Sanctum::actingAs($userB);
        $response = $this->postJson(self::ENDPOINT, ['code' => 'CODE_BBB']);

        $response->assertStatus(409)->assertJsonPath('success', false);

        // El token del tenant A no se pisó.
        $this->assertSame(1, InstagramConfig::count());
        $this->assertSame('PAGE_TOKEN_A', Crypt::decryptString(InstagramConfig::first()->page_access_token));
    }

    public function test_reconnecting_same_tenant_updates_without_duplicates(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        // Un único Http::fake que rutea el page token por el user token del bearer
        // (los stubs de Http::fake no se reemplazan al re-llamarlo, por eso no
        // podemos hacer fakeMeta dos veces con distinto token).
        $this->fakeMetaByCode([
            'CODE_AAA' => 'TOKEN_OLD',
            'CODE_BBB' => 'TOKEN_NEW',
        ]);

        $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA'])->assertOk();
        $this->postJson(self::ENDPOINT, ['code' => 'CODE_BBB'])->assertOk();

        $this->assertSame(1, InstagramConfig::count());
        $this->assertSame(1, Channel::where('tenant_id', $tenant->id)->count());
        $this->assertSame('TOKEN_NEW', Crypt::decryptString(InstagramConfig::first()->page_access_token));
    }

    public function test_webhook_subscription_failure_still_succeeds_with_warning(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'USER_TOKEN', 'token_type' => 'bearer'], 200);
            }
            if (str_contains($url, '/me/accounts')) {
                return Http::response(['data' => [[
                    'id' => 'PAGE_1',
                    'name' => 'Acme',
                    'access_token' => 'PAGE_TOKEN_1',
                    'instagram_business_account' => ['id' => 'IG_1', 'username' => 'acme'],
                ]]], 200);
            }
            if (str_contains($url, '/subscribed_apps')) {
                return Http::response(['error' => ['message' => 'nope']], 400);
            }

            return Http::response(['error' => ['message' => "unmapped {$url}"]], 404);
        });

        $response = $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'warnings');
        $this->assertSame(1, InstagramConfig::count());
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function createTenantAndUser(): array
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);

        return [$tenant, $user];
    }

    /**
     * Stubea el flujo OAuth de Instagram: oauth/access_token, me/accounts y
     * subscribed_apps. Rutea me/accounts por el conjunto de páginas dado.
     *
     * @param  list<array{page_id: string, name: string, ig_id: string, username: string, token: string}>  $pages
     */
    private function fakeMeta(array $pages): void
    {
        Http::fake(function ($request) use ($pages) {
            $url = (string) $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'USER_TOKEN', 'token_type' => 'bearer'], 200);
            }

            if (str_contains($url, '/me/accounts')) {
                return Http::response([
                    'data' => array_map(fn (array $p) => [
                        'id' => $p['page_id'],
                        'name' => $p['name'],
                        'access_token' => $p['token'],
                        'instagram_business_account' => [
                            'id' => $p['ig_id'],
                            'username' => $p['username'],
                        ],
                    ], $pages),
                ], 200);
            }

            if (str_contains($url, '/subscribed_apps')) {
                return Http::response(['success' => true], 200);
            }

            return Http::response(['error' => ['message' => "unmapped url {$url}"]], 404);
        });
    }

    /**
     * Variante que rutea el page token por el `code` OAuth: el intercambio del
     * code devuelve un user token distinto por code, y me/accounts devuelve como
     * page token el valor que corresponde a ese user token (leído del bearer).
     * Necesario cuando un test hace más de un onboarding en secuencia.
     *
     * @param  array<string, string>  $tokenByCode
     */
    private function fakeMetaByCode(array $tokenByCode): void
    {
        // user token entregado en el exchange = "USER_<pageToken>", así el
        // bearer de me/accounts identifica qué page token devolver.
        Http::fake(function ($request) use ($tokenByCode) {
            $url = (string) $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
                // fb_exchange_token: devolver el mismo user token extendido.
                if (($query['grant_type'] ?? null) === 'fb_exchange_token') {
                    return Http::response(['access_token' => $query['fb_exchange_token'], 'token_type' => 'bearer'], 200);
                }
                $code = $query['code'] ?? null;
                $pageToken = $tokenByCode[$code] ?? null;
                if (! $pageToken) {
                    return Http::response(['error' => ['message' => "unmapped code {$code}"]], 400);
                }

                return Http::response(['access_token' => 'USER_'.$pageToken, 'token_type' => 'bearer'], 200);
            }

            if (str_contains($url, '/me/accounts')) {
                $bearer = $request->header('Authorization')[0] ?? '';
                $pageToken = str_replace(['Bearer USER_', 'Bearer '], '', $bearer);

                return Http::response([
                    'data' => [[
                        'id' => 'PAGE_1',
                        'name' => 'Acme',
                        'access_token' => $pageToken,
                        'instagram_business_account' => ['id' => 'IG_1', 'username' => 'acme'],
                    ]],
                ], 200);
            }

            if (str_contains($url, '/subscribed_apps')) {
                return Http::response(['success' => true], 200);
            }

            return Http::response(['error' => ['message' => "unmapped url {$url}"]], 404);
        });
    }
}
