<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\InstagramConfig;
use App\Models\MessengerConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChannelReconnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.facebook.app_id', 'test-app-id');
        config()->set('services.facebook.app_secret', 'test-app-secret');
        config()->set('services.facebook.graph_version', 'v21.0');
    }

    public function test_reconnecting_a_disconnected_whatsapp_channel_reactivates_it_and_resets_contact_sync(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = WhatsAppConfig::create([
            'waba_id' => 'WABA_AAA',
            'phone_number_id' => 'PHONE_111',
            'bussines_token' => Crypt::encryptString('old-token'),
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'whatsapp_config_id' => $config->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp Business',
            'status' => 'active',
        ]);

        // Simular que ya se había disparado el sync SMB en un onboarding previo.
        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_COMPLETED,
            'contact_sync_requested_at' => now()->subDays(2),
            'contact_sync_contacts_count' => 40,
        ])->save();

        // Un único Http::fake() para todo el test: Http::fake() APILA stubs
        // sobre los anteriores en vez de reemplazarlos (Factory::fake() hace
        // stubCallbacks->merge(...), nunca reset), así que un segundo
        // Http::fake() a mitad de test no "reinicia" nada — el fake de la
        // desconexión seguiría activo y ensombrecería el de la reconexión.
        // El closure distingue fase por el token esperado en cada momento.
        $this->fakeMetaForDisconnectThenReconnect('WABA_AAA', 'PHONE_111', 'new-token');

        Sanctum::actingAs($owner);
        $this->postJson("/api/channels/{$channel->id}/disconnect")->assertOk();

        $channel->refresh();
        $config->refresh();
        $this->assertTrue($channel->isDisconnected());
        $this->assertSame(WhatsAppConfig::SYNC_PENDING, $config->contact_sync_status);
        $this->assertNull($config->contact_sync_requested_at);
        $this->assertSame(0, $config->contact_sync_contacts_count);

        // Reconectar: el mismo número (waba_id + phone_number_id) cae en el
        // mismo canal, y con contact_sync_requested_at limpio, el guard de
        // idempotencia de triggerContactSync vuelve a dejar pasar el disparo.
        $response = $this->postJson('/api/admin/channels/whatsapp-auth', [
            'code' => 'CODE_RECONNECT',
            'name' => 'WhatsApp Business',
            'data' => ['waba_id' => 'WABA_AAA', 'phone_number_id' => 'PHONE_111'],
        ]);
        $response->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, Channel::where('tenant_id', $tenant->id)->count());

        $channel->refresh();
        $config->refresh();
        $this->assertTrue($channel->isActive());
        $this->assertNull($channel->disconnected_at);
        $this->assertNull($channel->disconnected_by);
        $this->assertSame('new-token', Crypt::decryptString($config->bussines_token));

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/PHONE_111/smb_app_data')
            && ($request->data()['sync_type'] ?? null) === 'smb_app_state_sync');
    }

    public function test_reconnecting_a_disconnected_instagram_channel_reactivates_it(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = InstagramConfig::create([
            'tenant_id' => $tenant->id,
            'ig_user_id' => 'IG123',
            'page_id' => 'PAGE123',
            'username' => 'acme',
            'page_access_token' => Crypt::encryptString('old-ig-token'),
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'instagram_config_id' => $config->id,
            'type' => ChannelType::INSTAGRAM,
            'name' => '@acme',
            'status' => 'active',
        ]);

        Http::fake(['graph.facebook.com/*/subscribed_apps' => Http::sequence()
            ->push(['success' => true], 200)
            ->push(['data' => []], 200)]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/channels/{$channel->id}/disconnect")->assertOk();

        $channel->refresh();
        $this->assertTrue($channel->isDisconnected());

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'new-ig-token'], 200);
            }
            if (str_contains($url, '/me/accounts')) {
                return Http::response(['data' => [[
                    'id' => 'PAGE123',
                    'name' => 'Acme',
                    'access_token' => 'page-token',
                    'instagram_business_account' => ['id' => 'IG123', 'username' => 'acme'],
                ]]], 200);
            }
            if (str_contains($url, '/subscribed_apps')) {
                return $request->method() === 'GET'
                    ? Http::response(['data' => [['id' => 'test-app-id']]], 200)
                    : Http::response(['success' => true], 200);
            }

            return Http::response(['error' => ['message' => "unmapped {$url}"]], 404);
        });

        $response = $this->postJson('/api/admin/channels/instagram-auth', ['code' => 'CODE_RECONNECT']);
        $response->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, Channel::where('tenant_id', $tenant->id)->where('type', ChannelType::INSTAGRAM)->count());

        $channel->refresh();
        $this->assertTrue($channel->isActive());
        $this->assertNull($channel->disconnected_at);
    }

    public function test_reconnecting_a_disconnected_messenger_channel_reactivates_it(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = MessengerConfig::create([
            'tenant_id' => $tenant->id,
            'page_id' => 'PAGE456',
            'page_name' => 'Acme',
            'page_access_token' => Crypt::encryptString('old-fb-token'),
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'messenger_config_id' => $config->id,
            'type' => ChannelType::FACEBOOK,
            'name' => 'Acme',
            'status' => 'active',
        ]);

        Http::fake(['graph.facebook.com/*/subscribed_apps' => Http::sequence()
            ->push(['success' => true], 200)
            ->push(['data' => []], 200)]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/channels/{$channel->id}/disconnect")->assertOk();

        $channel->refresh();
        $this->assertTrue($channel->isDisconnected());

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'new-fb-token'], 200);
            }
            if (str_contains($url, '/me/accounts')) {
                return Http::response(['data' => [[
                    'id' => 'PAGE456',
                    'name' => 'Acme',
                    'access_token' => 'page-token',
                ]]], 200);
            }
            if (str_contains($url, '/subscribed_apps')) {
                return $request->method() === 'GET'
                    ? Http::response(['data' => [['id' => 'test-app-id']]], 200)
                    : Http::response(['success' => true], 200);
            }

            return Http::response(['error' => ['message' => "unmapped {$url}"]], 404);
        });

        $response = $this->postJson('/api/admin/channels/messenger-auth', ['code' => 'CODE_RECONNECT']);
        $response->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, Channel::where('tenant_id', $tenant->id)->where('type', ChannelType::FACEBOOK)->count());

        $channel->refresh();
        $this->assertTrue($channel->isActive());
        $this->assertNull($channel->disconnected_at);
    }

    public function test_shared_config_sync_state_is_not_reset_when_another_active_channel_remains(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = WhatsAppConfig::create([
            'waba_id' => 'WABA_SHARED',
            'phone_number_id' => 'PHONE_SHARED',
            'bussines_token' => Crypt::encryptString('token'),
            'contact_sync_status' => WhatsAppConfig::SYNC_COMPLETED,
            'contact_sync_requested_at' => now()->subHour(),
        ]);
        $channelA = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'whatsapp_config_id' => $config->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'A',
            'status' => 'active',
        ]);
        Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'whatsapp_config_id' => $config->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'B',
            'external_id' => 'other',
            'status' => 'active',
        ]);

        Http::fake();

        Sanctum::actingAs($owner);
        $this->postJson("/api/channels/{$channelA->id}/disconnect")->assertOk();

        $config->refresh();
        $this->assertSame(WhatsAppConfig::SYNC_COMPLETED, $config->contact_sync_status);
        $this->assertNotNull($config->contact_sync_requested_at);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function createUser(string $role): array
    {
        $tenant = $this->createTenantWithRoles('Acme '.uniqid());
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return [$tenant, $user];
    }

    /**
     * Un único fake que cubre el DELETE+GET de la desconexión (unsubscribe de
     * MetaWebhookUnsubscriber) y todo el flujo de reconexión (handleAuth), en
     * ese orden. Ver el comentario en el test: Http::fake() apila, así que
     * cubrir ambas fases en un solo closure evita que un segundo Http::fake()
     * quede ensombrecido por el primero.
     */
    private function fakeMetaForDisconnectThenReconnect(string $wabaId, string $phoneNumberId, string $newToken): void
    {
        // Estado mutable capturado por referencia: el primer DELETE marca que
        // ya pasamos por la fase de desconexión, así que el siguiente GET a
        // subscribed_apps (verificación del unsubscribe) debe decir "no
        // suscripta". Cualquier subscribed_apps posterior a eso pertenece a
        // la reconexión (POST + GET de subscribeToWebhooks), que sí debe
        // confirmar la suscripción para que el onboarding no falle.
        $disconnected = false;

        Http::fake(function ($request) use ($wabaId, $phoneNumberId, $newToken, &$disconnected) {
            $url = (string) $request->url();
            $method = $request->method();

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => $newToken, 'token_type' => 'bearer'], 200);
            }
            if (str_contains($url, "/{$wabaId}/phone_numbers")) {
                return Http::response(['data' => [[
                    'id' => $phoneNumberId,
                    'display_phone_number' => '+54 11 1111-1111',
                    'verified_name' => 'Acme',
                ]]], 200);
            }
            if (str_contains($url, "/{$wabaId}/subscribed_apps")) {
                if ($method === 'DELETE') {
                    $disconnected = true;

                    return Http::response(['success' => true], 200);
                }
                if ($method === 'POST') {
                    // El re-subscribe de la reconexión revierte el estado que
                    // dejó el DELETE de la desconexión.
                    $disconnected = false;

                    return Http::response(['success' => true], 200);
                }

                // GET de verificación: refleja el estado actual.
                return Http::response(['data' => $disconnected ? [] : [['id' => 'test-app-id']]], 200);
            }
            if (str_contains($url, "/{$phoneNumberId}/register")
                || str_contains($url, "/{$phoneNumberId}/smb_app_data")) {
                return Http::response(['success' => true], 200);
            }
            // businessAppState(): consulta is_on_biz_app antes de decidir si
            // pide el sync SMB (tanto en registerPhoneNumber como en el sync).
            if ($method === 'GET' && str_contains($url, "/{$phoneNumberId}?fields=")) {
                return Http::response(['is_on_biz_app' => true], 200);
            }

            return Http::response(['error' => ['message' => "unmapped url {$url}"]], 404);
        });
    }
}
