<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/admin/channels/whatsapp-auth';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.facebook.app_id', 'test-app-id');
        config()->set('services.facebook.app_secret', 'test-app-secret');
        config()->set('services.facebook.graph_version', 'v21.0');
    }

    public function test_user_can_connect_two_distinct_whatsapp_numbers_to_the_same_tenant(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeMetaWithRouter([
            'CODE_AAA' => ['waba' => 'WABA_AAA', 'phone' => 'PHONE_111', 'display' => '+54 11 1111-1111', 'token' => 'TOKEN_AAA'],
            'CODE_BBB' => ['waba' => 'WABA_BBB', 'phone' => 'PHONE_222', 'display' => '+54 11 2222-2222', 'token' => 'TOKEN_BBB'],
        ]);

        $first = $this->postJson(self::ENDPOINT, $this->payload('WABA_AAA', 'PHONE_111', 'CODE_AAA'));
        $first->assertOk()->assertJsonPath('success', true);

        $second = $this->postJson(self::ENDPOINT, $this->payload('WABA_BBB', 'PHONE_222', 'CODE_BBB'));
        $second->assertOk()->assertJsonPath('success', true);

        $this->assertSame(2, Channel::where('tenant_id', $tenant->id)->count());
        $this->assertSame(2, WhatsAppConfig::count());

        $configs = WhatsAppConfig::orderBy('id')->get();
        $this->assertSame('PHONE_111', $configs[0]->phone_number_id);
        $this->assertSame('PHONE_222', $configs[1]->phone_number_id);
        $this->assertSame('TOKEN_AAA', Crypt::decryptString($configs[0]->bussines_token));
        $this->assertSame('TOKEN_BBB', Crypt::decryptString($configs[1]->bussines_token));
    }

    public function test_reauthenticating_the_same_number_updates_the_token_without_creating_duplicates(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeMetaWithRouter([
            'CODE_FIRST' => ['waba' => 'WABA_AAA', 'phone' => 'PHONE_111', 'display' => '+54 11 1111-1111', 'token' => 'TOKEN_OLD'],
            'CODE_SECOND' => ['waba' => 'WABA_AAA', 'phone' => 'PHONE_111', 'display' => '+54 11 1111-1111', 'token' => 'TOKEN_NEW'],
        ]);

        $this->postJson(self::ENDPOINT, $this->payload('WABA_AAA', 'PHONE_111', 'CODE_FIRST'))->assertOk();
        $this->postJson(self::ENDPOINT, $this->payload('WABA_AAA', 'PHONE_111', 'CODE_SECOND'))->assertOk();

        $this->assertSame(1, Channel::where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, WhatsAppConfig::count());

        $config = WhatsAppConfig::first();
        $this->assertSame('TOKEN_NEW', Crypt::decryptString($config->bussines_token));
    }

    /**
     * Guard de idempotencia: un reauth del mismo número (segundo handleAuth para
     * el mismo phone_number_id) no debe volver a pedir smb_app_data. Cubre el
     * incidente real donde reintentos de onboarding duplicaron el contact sync y
     * el history sync, quemando la cuota de rate limit de la app de Meta.
     */
    public function test_reauthenticating_the_same_number_does_not_duplicate_smb_app_data_calls(): void
    {
        [, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeMetaWithRouter([
            'CODE_FIRST' => ['waba' => 'WABA_AAA', 'phone' => 'PHONE_111', 'display' => '+54 11 1111-1111', 'token' => 'TOKEN_OLD'],
            'CODE_SECOND' => ['waba' => 'WABA_AAA', 'phone' => 'PHONE_111', 'display' => '+54 11 1111-1111', 'token' => 'TOKEN_NEW'],
        ]);

        $this->postJson(self::ENDPOINT, $this->payload('WABA_AAA', 'PHONE_111', 'CODE_FIRST'))->assertOk();
        $this->postJson(self::ENDPOINT, $this->payload('WABA_AAA', 'PHONE_111', 'CODE_SECOND'))->assertOk();

        $smbAppDataCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains((string) $pair[0]->url(), '/PHONE_111/smb_app_data'));

        // sync_type=smb_app_state_sync + sync_type=history: uno de cada uno, no
        // dos, aunque handleAuth haya corrido dos veces para el mismo número.
        $this->assertCount(2, $smbAppDataCalls);

        $syncTypes = $smbAppDataCalls->map(fn ($pair) => $pair[0]->data()['sync_type'] ?? null)->sort()->values();
        $this->assertSame(['history', 'smb_app_state_sync'], $syncTypes->all());

        $config = WhatsAppConfig::first();
        $this->assertSame(WhatsAppConfig::SYNC_SYNCING, $config->contact_sync_status);
        $this->assertSame(WhatsAppConfig::SYNC_SYNCING, $config->contact_history_sync_status);
    }

    public function test_another_user_in_the_tenant_cannot_take_over_a_number_already_connected(): void
    {
        [$tenant, $owner] = $this->createTenantAndUser();
        $otherUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::EMPLOYEE,
        ]);

        $this->fakeMetaWithRouter([
            'CODE_OWNER' => ['waba' => 'WABA_AAA', 'phone' => 'PHONE_111', 'display' => '+54 11 1111-1111', 'token' => 'TOKEN_OWNER'],
            'CODE_OTHER' => ['waba' => 'WABA_AAA', 'phone' => 'PHONE_111', 'display' => '+54 11 1111-1111', 'token' => 'TOKEN_OTHER'],
        ]);

        Sanctum::actingAs($owner);
        $this->postJson(self::ENDPOINT, $this->payload('WABA_AAA', 'PHONE_111', 'CODE_OWNER'))->assertOk();

        Sanctum::actingAs($otherUser);
        $response = $this->postJson(self::ENDPOINT, $this->payload('WABA_AAA', 'PHONE_111', 'CODE_OTHER'));

        $response->assertStatus(409)->assertJsonPath('success', false);

        $this->assertSame(1, Channel::where('tenant_id', $tenant->id)->count());
        $this->assertSame($owner->id, Channel::first()->user_id);
        $this->assertSame('TOKEN_OWNER', Crypt::decryptString(WhatsAppConfig::first()->bussines_token));
    }

    public function test_onboarding_fails_when_phone_number_cannot_be_registered(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'TOKEN_AAA', 'token_type' => 'bearer'], 200);
            }
            if (str_contains($url, '/WABA_AAA/phone_numbers')) {
                return Http::response(['data' => [['id' => 'PHONE_111', 'display_phone_number' => '+54 11 1111-1111']]], 200);
            }
            // El register falla, p. ej. número con 2FA activo.
            if (str_contains($url, '/PHONE_111/register')) {
                return Http::response(['error' => ['code' => 133005, 'message' => 'PIN required']], 400);
            }

            return Http::response(['error' => ['message' => "unmapped url {$url}"]], 404);
        });

        $response = $this->postJson(self::ENDPOINT, $this->payload('WABA_AAA', 'PHONE_111', 'CODE_AAA'));

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_onboarding_succeeds_when_number_is_already_registered(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'TOKEN_AAA', 'token_type' => 'bearer'], 200);
            }
            if (str_contains($url, '/WABA_AAA/phone_numbers')) {
                return Http::response(['data' => [['id' => 'PHONE_111', 'display_phone_number' => '+54 11 1111-1111']]], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/WABA_AAA/subscribed_apps')) {
                return Http::response(['data' => [['id' => 'test-app-id']]], 200);
            }
            // Onboarding normal: Meta ya registró el número.
            if (str_contains($url, '/PHONE_111/register')) {
                return Http::response(['error' => ['code' => 133015, 'message' => 'Phone number already registered']], 400);
            }

            return Http::response(['success' => true], 200);
        });

        $response = $this->postJson(self::ENDPOINT, $this->payload('WABA_AAA', 'PHONE_111', 'CODE_AAA'));

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, Channel::where('tenant_id', $tenant->id)->count());
    }

    public function test_onboarding_does_not_request_contact_sync_when_meta_does_not_confirm_waba_subscription(): void
    {
        [, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'TOKEN_AAA'], 200);
            }
            if (str_contains($url, '/WABA_AAA/phone_numbers')) {
                return Http::response(['data' => [['id' => 'PHONE_111']]], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/PHONE_111?fields=')) {
                return Http::response(['is_on_biz_app' => true], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, '/WABA_AAA/subscribed_apps')) {
                return Http::response(['success' => true], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/WABA_AAA/subscribed_apps')) {
                return Http::response(['data' => []], 200);
            }

            return Http::response(['error' => ['message' => "unmapped url {$url}"]], 404);
        });

        $this->postJson(self::ENDPOINT, $this->payload('WABA_AAA', 'PHONE_111', 'CODE_AAA'))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Meta no confirmó la suscripción de webhooks para esta cuenta de WhatsApp. Intentá reconectar el canal.');

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/PHONE_111/smb_app_data'));
    }

    public function test_onboarding_accepts_the_v26_waba_subscription_response_shape(): void
    {
        [, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'TOKEN_AAA'], 200);
            }
            if (str_contains($url, '/WABA_AAA/phone_numbers')) {
                return Http::response(['data' => [['id' => 'PHONE_111']]], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/PHONE_111?fields=')) {
                return Http::response(['is_on_biz_app' => true], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, '/WABA_AAA/subscribed_apps')) {
                return Http::response(['success' => true], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/WABA_AAA/subscribed_apps')) {
                return Http::response([
                    'data' => [[
                        'whatsapp_business_api_data' => ['id' => 'test-app-id'],
                    ]],
                ], 200);
            }
            if (str_contains($url, '/PHONE_111/smb_app_data')) {
                return Http::response(['request_id' => 'REQ_123'], 200);
            }

            return Http::response(['error' => ['message' => "unmapped url {$url}"]], 404);
        });

        $this->postJson(self::ENDPOINT, $this->payload('WABA_AAA', 'PHONE_111', 'CODE_AAA'))
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains((string) $request->url(), '/v26.0/WABA_AAA/subscribed_apps'));
    }

    public function test_contact_sync_400_persists_the_meta_error_without_type_error(): void
    {
        $this->assertContactSyncRejectionIsPersisted(400, [
            'message' => '(#135000) Generic user error',
            'type' => 'OAuthException',
            'code' => 135000,
        ], '[Meta 135000] (#135000) Generic user error');
    }

    public function test_contact_sync_unexpected_status_persists_the_meta_error_without_type_error(): void
    {
        $this->assertContactSyncRejectionIsPersisted(503, [
            'message' => 'Graph API temporarily unavailable',
            'type' => 'OAuthException',
            'code' => 2,
            'error_subcode' => 99,
        ], '[Meta 2/99] Graph API temporarily unavailable');
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
     * @return array{code: string, name: string, data: array{waba_id: string, phone_number_id: string}}
     */
    private function payload(string $wabaId, string $phoneNumberId, string $code): array
    {
        return [
            'code' => $code,
            'name' => 'WhatsApp Business',
            'data' => [
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
            ],
        ];
    }

    /**
     * Stubea Graph API ruteando por el `code` OAuth presente en cada request.
     * Una sola llamada a Http::fake() para que la respuesta dependa de la URL,
     * no del orden de registro (los handlers de Http::fake() no se reemplazan).
     *
     * @param  array<string, array{waba: string, phone: string, display: string, token: string}>  $byCode
     */
    private function fakeMetaWithRouter(array $byCode): void
    {
        Http::fake(function ($request) use ($byCode) {
            $url = (string) $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
                $code = $query['code'] ?? null;
                $entry = $byCode[$code] ?? null;
                if (! $entry) {
                    return Http::response(['error' => ['message' => "unmapped code {$code}"]], 400);
                }

                return Http::response([
                    'access_token' => $entry['token'],
                    'token_type' => 'bearer',
                ], 200);
            }

            foreach ($byCode as $entry) {
                if (str_contains($url, "/{$entry['waba']}/phone_numbers")) {
                    return Http::response([
                        'data' => [[
                            'id' => $entry['phone'],
                            'display_phone_number' => $entry['display'],
                            'verified_name' => 'Acme',
                        ]],
                    ], 200);
                }
                if (str_contains($url, "/{$entry['waba']}/subscribed_apps")) {
                    return $request->method() === 'GET'
                        ? Http::response(['data' => [['id' => 'test-app-id']]], 200)
                        : Http::response(['success' => true], 200);
                }
                if (str_contains($url, "/{$entry['phone']}/register")
                    || str_contains($url, "/{$entry['phone']}/smb_app_data")) {
                    return Http::response(['success' => true], 200);
                }
            }

            return Http::response(['error' => ['message' => "unmapped url {$url}"]], 404);
        });
    }

    /**
     * Verifica el onboarding completo cuando Meta rechaza POST /smb_app_data.
     * La conexión debe sobrevivir con una advertencia y conservar el error real.
     *
     * @param  array<string, int|string>  $metaError
     */
    private function assertContactSyncRejectionIsPersisted(
        int $status,
        array $metaError,
        string $expectedError
    ): void {
        [, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        Http::fake(function ($request) use ($status, $metaError) {
            $url = (string) $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'TOKEN_AAA'], 200);
            }

            if (str_contains($url, '/WABA_AAA/phone_numbers')) {
                return Http::response([
                    'data' => [[
                        'id' => 'PHONE_111',
                        'display_phone_number' => '+54 11 1111-1111',
                    ]],
                ], 200);
            }

            if ($request->method() === 'GET' && str_contains($url, '/PHONE_111?fields=')) {
                return Http::response([
                    'id' => 'PHONE_111',
                    'is_on_biz_app' => true,
                    'platform_type' => 'CLOUD_API',
                ], 200);
            }

            if (str_contains($url, '/WABA_AAA/subscribed_apps')) {
                return $request->method() === 'GET'
                    ? Http::response(['data' => [['id' => 'test-app-id']]], 200)
                    : Http::response(['success' => true], 200);
            }

            if (str_contains($url, '/PHONE_111/smb_app_data')) {
                return Http::response(['error' => $metaError], $status);
            }

            return Http::response(['error' => ['message' => "unmapped url {$url}"]], 404);
        });

        $response = $this->postJson(
            self::ENDPOINT,
            $this->payload('WABA_AAA', 'PHONE_111', 'CODE_AAA')
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('warnings.0', 'No se pudo iniciar la sincronización de contactos. Contactá a soporte.');

        $config = WhatsAppConfig::where('phone_number_id', 'PHONE_111')->firstOrFail();
        $this->assertSame(WhatsAppConfig::SYNC_FAILED, $config->contact_sync_status);
        $this->assertSame($expectedError, $config->contact_sync_error);
    }
}
