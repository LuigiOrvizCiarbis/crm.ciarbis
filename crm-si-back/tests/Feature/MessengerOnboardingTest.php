<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\MessengerConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessengerOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/admin/channels/messenger-auth';

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
            ['page_id' => 'PAGE_1', 'name' => 'Acme SRL', 'token' => 'PAGE_TOKEN_1'],
        ]);

        $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $config = MessengerConfig::first();
        $this->assertSame('PAGE_1', $config->page_id);
        $this->assertSame('Acme SRL', $config->page_name);
        $this->assertSame('PAGE_TOKEN_1', Crypt::decryptString($config->page_access_token));

        $channel = Channel::first();
        $this->assertSame(ChannelType::FACEBOOK, $channel->type);
        $this->assertSame('PAGE_1', $channel->external_id);
        $this->assertSame('Acme SRL', $channel->name);
        $this->assertSame($config->id, $channel->messenger_config_id);
    }

    /**
     * El token nunca se guarda en claro.
     */
    public function test_page_token_is_stored_encrypted(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeMeta([
            ['page_id' => 'PAGE_1', 'name' => 'Acme', 'token' => 'PAGE_TOKEN_1'],
        ]);

        $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA'])->assertOk();

        $raw = MessengerConfig::first()->getRawOriginal('page_access_token');
        $this->assertNotSame('PAGE_TOKEN_1', $raw);
        $this->assertSame('PAGE_TOKEN_1', Crypt::decryptString($raw));
    }

    /**
     * Delta clave frente a Instagram: una página SIN cuenta de Instagram
     * vinculada es perfectamente conectable en Messenger.
     */
    public function test_page_without_instagram_account_connects(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        // El fake devuelve id/name/access_token y ningún instagram_business_account.
        $this->fakeMeta([
            ['page_id' => 'PAGE_SIN_IG', 'name' => 'Solo FB', 'token' => 'PAGE_TOKEN_X'],
        ]);

        $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('PAGE_SIN_IG', MessengerConfig::first()->page_id);
    }

    /**
     * El campo message_echoes es lo que hace que lleguen los mensajes escritos
     * desde la app de Messenger. Sin él el handoff se rompe en silencio.
     */
    public function test_subscribes_to_message_echoes_field(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeMeta([
            ['page_id' => 'PAGE_1', 'name' => 'Acme', 'token' => 'PAGE_TOKEN_1'],
        ]);

        $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA'])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains((string) $request->url(), '/subscribed_apps')) {
                return false;
            }

            $fields = explode(',', (string) $request['subscribed_fields']);

            return in_array('messages', $fields, true)
                && in_array('message_echoes', $fields, true)
                && in_array('messaging_postbacks', $fields, true);
        });
    }

    public function test_multiple_pages_returns_selection_without_reexchanging_code(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeMeta([
            ['page_id' => 'PAGE_1', 'name' => 'Acme', 'token' => 'PAGE_TOKEN_1'],
            ['page_id' => 'PAGE_2', 'name' => 'Beta', 'token' => 'PAGE_TOKEN_2'],
        ]);

        $first = $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA']);

        $first->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('requires_page_selection', true)
            ->assertJsonCount(2, 'pages');

        $this->assertSame(0, MessengerConfig::count());

        $token = $first->json('onboarding_token');
        $this->assertNotEmpty($token);

        // Segunda vuelta: sin code, con el token de onboarding.
        $this->postJson(self::ENDPOINT, ['onboarding_token' => $token, 'page_id' => 'PAGE_2'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $config = MessengerConfig::first();
        $this->assertSame('PAGE_2', $config->page_id);
        $this->assertSame('PAGE_TOKEN_2', Crypt::decryptString($config->page_access_token));

        // El code se canjeó una sola vez: la segunda vuelta usa el user token
        // del cache y no vuelve a /oauth/access_token.
        //
        // Vuelta 1: canje del code + extensión a long-lived + /me/accounts = 3.
        // Vuelta 2: /me/accounts + /subscribed_apps = 2.
        Http::assertSentCount(5);
        $this->assertSame(
            2,
            collect(Http::recorded())
                ->filter(fn ($pair) => str_contains((string) $pair[0]->url(), '/oauth/access_token'))
                ->count(),
        );
    }

    public function test_onboarding_token_is_single_use(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeMeta([
            ['page_id' => 'PAGE_1', 'name' => 'Acme', 'token' => 'PAGE_TOKEN_1'],
            ['page_id' => 'PAGE_2', 'name' => 'Beta', 'token' => 'PAGE_TOKEN_2'],
        ]);

        $token = $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA'])->json('onboarding_token');

        $this->postJson(self::ENDPOINT, ['onboarding_token' => $token, 'page_id' => 'PAGE_1'])->assertOk();

        // Reusar el mismo token: la entrada de cache ya se invalidó.
        $this->postJson(self::ENDPOINT, ['onboarding_token' => $token, 'page_id' => 'PAGE_1'])
            ->assertStatus(410);
    }

    public function test_page_connected_by_another_tenant_returns_409(): void
    {
        [$tenantA, $userA] = $this->createTenantAndUser();
        [$tenantB, $userB] = $this->createTenantAndUser();

        $this->fakeMeta([
            ['page_id' => 'PAGE_1', 'name' => 'Acme', 'token' => 'PAGE_TOKEN_A'],
        ]);

        Sanctum::actingAs($userA);
        $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA'])->assertOk();

        Sanctum::actingAs($userB);
        $this->postJson(self::ENDPOINT, ['code' => 'CODE_BBB'])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        // El token del tenant A no se pisó.
        $this->assertSame(1, MessengerConfig::count());
        $this->assertSame('PAGE_TOKEN_A', Crypt::decryptString(MessengerConfig::first()->page_access_token));
    }

    public function test_reconnecting_same_tenant_updates_without_duplicates(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeMetaByCode([
            'CODE_AAA' => 'TOKEN_OLD',
            'CODE_BBB' => 'TOKEN_NEW',
        ]);

        $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA'])->assertOk();
        $this->postJson(self::ENDPOINT, ['code' => 'CODE_BBB'])->assertOk();

        $this->assertSame(1, MessengerConfig::count());
        $this->assertSame(1, Channel::where('tenant_id', $tenant->id)->count());
        $this->assertSame('TOKEN_NEW', Crypt::decryptString(MessengerConfig::first()->page_access_token));
    }

    public function test_no_pages_returns_422(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeMeta([]);

        $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, MessengerConfig::count());
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
                ]]], 200);
            }
            if (str_contains($url, '/subscribed_apps')) {
                return Http::response(['error' => ['message' => 'nope']], 400);
            }

            return Http::response(['error' => ['message' => "unmapped {$url}"]], 404);
        });

        $response = $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA']);

        // El canal queda conectado; el fallo de suscripción es un warning.
        $response->assertOk()->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('warnings'));
        $this->assertSame(1, MessengerConfig::count());
    }

    public function test_requires_authentication(): void
    {
        $this->postJson(self::ENDPOINT, ['code' => 'CODE_AAA'])->assertStatus(401);
    }

    public function test_missing_code_and_token_is_rejected(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, [])->assertStatus(422);
    }

    // ---------------------------------------------------------------------

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function createTenantAndUser(): array
    {
        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);

        return [$tenant, $user];
    }

    /**
     * @param  list<array{page_id: string, name: string, token: string}>  $pages
     */
    private function fakeMeta(array $pages): void
    {
        $data = array_map(fn (array $p) => [
            'id' => $p['page_id'],
            'name' => $p['name'],
            'access_token' => $p['token'],
        ], $pages);

        Http::fake(function ($request) use ($data) {
            $url = (string) $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'USER_TOKEN', 'token_type' => 'bearer'], 200);
            }
            if (str_contains($url, '/me/accounts')) {
                return Http::response(['data' => $data], 200);
            }
            if (str_contains($url, '/subscribed_apps')) {
                return Http::response(['success' => true], 200);
            }

            return Http::response(['error' => ['message' => "unmapped {$url}"]], 404);
        });
    }

    /**
     * Variante que devuelve un page token distinto según el code canjeado. Los
     * stubs de Http::fake no se reemplazan al re-llamarlo, así que para probar
     * una reconexión hace falta un único fake que rutee por code.
     *
     * Ojo: MetaOAuth::exchangeCodeForToken hace DOS llamadas a
     * /oauth/access_token — el canje del code y la extensión a long-lived con
     * fb_exchange_token. La segunda ya no lleva el code, así que el ruteo del
     * segundo hop se hace por el token que viene en fb_exchange_token.
     *
     * @param  array<string, string>  $tokenByCode
     */
    private function fakeMetaByCode(array $tokenByCode): void
    {
        Http::fake(function ($request) use ($tokenByCode) {
            $url = (string) $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                // Hop 2: extensión a long-lived. Preserva el token que recibe.
                if (str_contains($url, 'fb_exchange_token=')) {
                    foreach ($tokenByCode as $pageToken) {
                        if (str_contains($url, 'USER_'.$pageToken)) {
                            return Http::response(['access_token' => 'USER_'.$pageToken], 200);
                        }
                    }

                    return Http::response(['access_token' => 'USER_UNKNOWN'], 200);
                }

                // Hop 1: canje del code.
                foreach ($tokenByCode as $code => $pageToken) {
                    if (str_contains($url, $code)) {
                        return Http::response(['access_token' => 'USER_'.$pageToken], 200);
                    }
                }

                return Http::response(['access_token' => 'USER_UNKNOWN'], 200);
            }

            if (str_contains($url, '/me/accounts')) {
                // El page token se deriva del user token del bearer.
                $userToken = (string) ($request->header('Authorization')[0] ?? '');
                $pageToken = str_replace('Bearer USER_', '', $userToken);

                return Http::response(['data' => [[
                    'id' => 'PAGE_1',
                    'name' => 'Acme',
                    'access_token' => $pageToken,
                ]]], 200);
            }

            if (str_contains($url, '/subscribed_apps')) {
                return Http::response(['success' => true], 200);
            }

            return Http::response(['error' => ['message' => "unmapped {$url}"]], 404);
        });
    }
}
