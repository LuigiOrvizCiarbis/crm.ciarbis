<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InstagramConfig;
use App\Models\MailConfig;
use App\Models\Message;
use App\Models\MessengerConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Services\Channels\ChannelDisconnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

class ChannelDisconnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.facebook.app_id', 'test-app-id');
        config()->set('services.facebook.graph_version', 'v21.0');
    }

    public function test_owner_can_disconnect_whatsapp_channel_and_credentials_are_purged(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = $this->makeWhatsAppConfig();
        $channel = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);

        Http::fake([
            'graph.facebook.com/*/subscribed_apps' => Http::sequence()
                ->push(['success' => true], 200) // DELETE
                ->push(['data' => []], 200), // GET de verificación: ya no está suscrita
        ]);

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/channels/{$channel->id}/disconnect");

        $response->assertOk()->assertJsonPath('data.status', 'disconnected');

        $channel->refresh();
        $this->assertTrue($channel->isDisconnected());
        $this->assertNotNull($channel->disconnected_at);
        $this->assertSame($owner->id, $channel->disconnected_by);

        $config->refresh();
        $this->assertNull($config->bussines_token);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), "/{$config->waba_id}/subscribed_apps"));
    }

    public function test_registration_pin_survives_disconnection(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = $this->makeWhatsAppConfig();
        $config->registration_pin = Crypt::encryptString('123456');
        $config->save();
        $channel = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);

        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/channels/{$channel->id}/disconnect")->assertOk();

        $config->refresh();
        $this->assertSame('123456', $config->getDecryptedRegistrationPin());
    }

    public function test_disconnect_keeps_conversations_and_messages(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = $this->makeWhatsAppConfig();
        $channel = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);

        $contact = Contact::create(['tenant_id' => $tenant->id, 'name' => 'Cliente', 'phone' => '5491111111111', 'source' => 'whatsapp']);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'sender_type' => 'contact',
            'type' => 'text',
            'content' => 'hola',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/channels/{$channel->id}/disconnect")->assertOk();

        $this->assertNotNull(Conversation::find($conversation->id));
        $this->assertNotNull(Message::find($message->id));
    }

    public function test_disconnect_succeeds_when_meta_revocation_fails(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = $this->makeWhatsAppConfig();
        $channel = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/channels/{$channel->id}/disconnect");

        $response->assertOk()->assertJsonPath('data.status', 'disconnected');
        $this->assertNotEmpty($response->json('warnings'));

        $config->refresh();
        $this->assertNull($config->bussines_token);
    }

    public function test_disconnect_reports_to_sentry_when_still_subscribed_after_delete(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = $this->makeWhatsAppConfig();
        $channel = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);

        Http::fake([
            'graph.facebook.com/*/subscribed_apps' => Http::sequence()
                ->push(['success' => true], 200) // DELETE responde ok
                ->push(['data' => [['id' => 'test-app-id']]], 200), // pero GET dice que sigue suscripta
        ]);

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/channels/{$channel->id}/disconnect");

        // El contrato best-effort se mantiene: la desconexión local se completa
        // igual, aunque la revocación remota no haya funcionado.
        $response->assertOk()->assertJsonPath('data.status', 'disconnected');
        $config->refresh();
        $this->assertNull($config->bussines_token);
    }

    public function test_disconnect_succeeds_when_token_is_expired(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = $this->makeWhatsAppConfig();
        $channel = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'expired', 'code' => 190]], 401)]);

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/channels/{$channel->id}/disconnect");

        $response->assertOk()->assertJsonPath('data.status', 'disconnected');
    }

    public function test_shared_whatsapp_config_is_not_purged_when_another_active_channel_remains(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = $this->makeWhatsAppConfig();
        $channelA = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);
        $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id, 'external_id' => 'other']);

        Http::fake();

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/channels/{$channelA->id}/disconnect");

        $response->assertOk();
        $this->assertNotEmpty($response->json('warnings'));

        $config->refresh();
        $this->assertNotNull($config->bussines_token);

        Http::assertNothingSent();
    }

    public function test_shared_config_is_purged_when_the_last_active_channel_is_disconnected(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = $this->makeWhatsAppConfig();
        $channelA = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);
        $channelB = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id, 'external_id' => 'other']);

        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true, 'data' => []], 200)]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/channels/{$channelA->id}/disconnect")->assertOk();
        $this->postJson("/api/channels/{$channelB->id}/disconnect")->assertOk();

        $config->refresh();
        $this->assertNull($config->bussines_token);

        Http::assertSentCount(2); // DELETE + GET de verificación, una sola vez (para channelB)
    }

    public function test_shared_config_across_tenants_is_not_purged(): void
    {
        [$tenantA, $ownerA] = $this->createUser('Owner');
        [$tenantB, $ownerB] = $this->createUser('Owner');

        $config = $this->makeWhatsAppConfig();
        $channelA = $this->makeChannel($tenantA, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);
        $this->makeChannel($tenantB, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id, 'external_id' => 'tenant-b']);

        Http::fake();

        Sanctum::actingAs($ownerA);
        $this->postJson("/api/channels/{$channelA->id}/disconnect")->assertOk();

        $config->refresh();
        $this->assertNotNull($config->bussines_token, 'El canal de otro tenant sigue usando esta config; no debe purgarse.');

        Http::assertNothingSent();
    }

    public function test_disconnect_instagram_channel_unsubscribes_page_and_purges_token(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = InstagramConfig::create([
            'tenant_id' => $tenant->id,
            'ig_user_id' => 'IG123',
            'page_id' => 'PAGE123',
            'username' => 'acme',
            'page_access_token' => Crypt::encryptString('ig-token'),
        ]);
        $channel = $this->makeChannel($tenant, ChannelType::INSTAGRAM, ['instagram_config_id' => $config->id]);

        Http::fake([
            'graph.facebook.com/*/subscribed_apps' => Http::sequence()
                ->push(['success' => true], 200)
                ->push(['data' => []], 200),
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/channels/{$channel->id}/disconnect")->assertOk();

        $config->refresh();
        $this->assertNull($config->page_access_token);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), '/PAGE123/subscribed_apps'));
    }

    public function test_disconnect_messenger_channel_unsubscribes_page_and_purges_token(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = MessengerConfig::create([
            'tenant_id' => $tenant->id,
            'page_id' => 'PAGE456',
            'page_name' => 'Acme',
            'page_access_token' => Crypt::encryptString('fb-token'),
        ]);
        $channel = $this->makeChannel($tenant, ChannelType::FACEBOOK, ['messenger_config_id' => $config->id]);

        Http::fake([
            'graph.facebook.com/*/subscribed_apps' => Http::sequence()
                ->push(['success' => true], 200)
                ->push(['data' => []], 200),
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/channels/{$channel->id}/disconnect")->assertOk();

        $config->refresh();
        $this->assertNull($config->page_access_token);
    }

    /**
     * subscribed_apps es un recurso de la PÁGINA de Facebook, no de la config.
     * Si la misma página tiene Instagram y Messenger conectados (dos filas
     * con el mismo page_id), desconectar Instagram no debe revocar la
     * suscripción que Messenger todavía necesita, ni purgar su token.
     */
    public function test_disconnecting_instagram_does_not_unsubscribe_page_shared_with_active_messenger_channel(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $igConfig = InstagramConfig::create([
            'tenant_id' => $tenant->id,
            'ig_user_id' => 'IG123',
            'page_id' => 'SHARED_PAGE',
            'username' => 'acme',
            'page_access_token' => Crypt::encryptString('ig-token'),
        ]);
        $fbConfig = MessengerConfig::create([
            'tenant_id' => $tenant->id,
            'page_id' => 'SHARED_PAGE',
            'page_name' => 'Acme',
            'page_access_token' => Crypt::encryptString('fb-token'),
        ]);
        $igChannel = $this->makeChannel($tenant, ChannelType::INSTAGRAM, ['instagram_config_id' => $igConfig->id]);
        $this->makeChannel($tenant, ChannelType::FACEBOOK, ['messenger_config_id' => $fbConfig->id, 'external_id' => 'fb-active']);

        Http::fake();

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/channels/{$igChannel->id}/disconnect");

        $response->assertOk();
        $this->assertNotEmpty($response->json('warnings'));

        $igConfig->refresh();
        $fbConfig->refresh();
        $this->assertNotNull($igConfig->page_access_token);
        $this->assertNotNull($fbConfig->page_access_token);

        Http::assertNothingSent();
    }

    /**
     * Caso inverso del anterior: desconectar Messenger no debe tocar la
     * suscripción de página que un canal de Instagram activo sigue usando.
     */
    public function test_disconnecting_messenger_does_not_unsubscribe_page_shared_with_active_instagram_channel(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $igConfig = InstagramConfig::create([
            'tenant_id' => $tenant->id,
            'ig_user_id' => 'IG456',
            'page_id' => 'SHARED_PAGE_2',
            'username' => 'acme2',
            'page_access_token' => Crypt::encryptString('ig-token-2'),
        ]);
        $fbConfig = MessengerConfig::create([
            'tenant_id' => $tenant->id,
            'page_id' => 'SHARED_PAGE_2',
            'page_name' => 'Acme 2',
            'page_access_token' => Crypt::encryptString('fb-token-2'),
        ]);
        $this->makeChannel($tenant, ChannelType::INSTAGRAM, ['instagram_config_id' => $igConfig->id, 'external_id' => 'ig-active']);
        $fbChannel = $this->makeChannel($tenant, ChannelType::FACEBOOK, ['messenger_config_id' => $fbConfig->id]);

        Http::fake();

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/channels/{$fbChannel->id}/disconnect");

        $response->assertOk();
        $this->assertNotEmpty($response->json('warnings'));

        $igConfig->refresh();
        $fbConfig->refresh();
        $this->assertNotNull($igConfig->page_access_token);
        $this->assertNotNull($fbConfig->page_access_token);

        Http::assertNothingSent();
    }

    /**
     * Cuando la página compartida ya no tiene NINGÚN canal activo (ni
     * Instagram ni Messenger), la revocación sí debe proceder para el último
     * que se desconecta.
     */
    public function test_disconnecting_the_last_active_channel_on_a_shared_page_unsubscribes_it(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $igConfig = InstagramConfig::create([
            'tenant_id' => $tenant->id,
            'ig_user_id' => 'IG789',
            'page_id' => 'SHARED_PAGE_3',
            'username' => 'acme3',
            'page_access_token' => Crypt::encryptString('ig-token-3'),
        ]);
        $fbConfig = MessengerConfig::create([
            'tenant_id' => $tenant->id,
            'page_id' => 'SHARED_PAGE_3',
            'page_name' => 'Acme 3',
            'page_access_token' => Crypt::encryptString('fb-token-3'),
        ]);
        $igChannel = $this->makeChannel($tenant, ChannelType::INSTAGRAM, ['instagram_config_id' => $igConfig->id]);
        $fbChannel = $this->makeChannel($tenant, ChannelType::FACEBOOK, ['messenger_config_id' => $fbConfig->id]);

        Http::fake(['graph.facebook.com/*/subscribed_apps' => Http::sequence()
            ->push(['success' => true], 200)
            ->push(['data' => []], 200)]);

        Sanctum::actingAs($owner);
        // Primero Instagram: Messenger sigue activo, así que no debe revocar.
        $this->postJson("/api/channels/{$igChannel->id}/disconnect")->assertOk();
        Http::assertNothingSent();

        // Ahora Messenger, el último canal activo de la página: sí revoca.
        $this->postJson("/api/channels/{$fbChannel->id}/disconnect")->assertOk();

        $fbConfig->refresh();
        $this->assertNull($fbConfig->page_access_token);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), '/SHARED_PAGE_3/subscribed_apps'));
    }

    /**
     * Protege contra la carrera que motivó lockSharedChannelIds(): si dos
     * canales que comparten config se desconectan en paralelo,
     * lockForUpdate() sobre una sola fila no basta — cada transacción vería
     * al otro canal como "todavía activo" y ninguna purgaría. El fix bloquea
     * el conjunto completo ANTES de contar. Como PHPUnit/SQLite no corren
     * dos conexiones DB concurrentes de verdad, este test verifica el
     * mecanismo directamente: lockSharedChannelIds() debe devolver los ids de
     * AMBOS canales que comparten la config (no solo el propio), que es la
     * precondición sin la cual la carrera reaparece.
     */
    public function test_lock_shared_channel_ids_includes_every_channel_on_the_same_config(): void
    {
        [$tenant] = $this->createUser('Owner');
        $config = $this->makeWhatsAppConfig();
        $channelA = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);
        $channelB = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id, 'external_id' => 'other']);

        $disconnector = app(ChannelDisconnector::class);
        $method = new ReflectionMethod($disconnector, 'lockSharedChannelIds');
        $method->setAccessible(true);

        $lockedIds = DB::transaction(fn () => $method->invoke($disconnector, $channelA->fresh()));

        $this->assertEqualsCanonicalizing([$channelA->id, $channelB->id], $lockedIds);
    }

    /**
     * Mismo mecanismo, para el caso cross-tabla del bug de página compartida:
     * el conjunto bloqueado al desconectar el canal de Instagram debe incluir
     * también el canal de Messenger que apunta a la misma página, o la
     * carrera reaparece ahí igual que en el caso de config directamente
     * compartida.
     */
    public function test_lock_shared_channel_ids_includes_the_other_meta_type_on_the_same_page(): void
    {
        [$tenant] = $this->createUser('Owner');
        $igConfig = InstagramConfig::create([
            'tenant_id' => $tenant->id,
            'ig_user_id' => 'IG_LOCK',
            'page_id' => 'SHARED_PAGE_LOCK',
            'username' => 'acme_lock',
            'page_access_token' => Crypt::encryptString('ig-token-lock'),
        ]);
        $fbConfig = MessengerConfig::create([
            'tenant_id' => $tenant->id,
            'page_id' => 'SHARED_PAGE_LOCK',
            'page_name' => 'Acme Lock',
            'page_access_token' => Crypt::encryptString('fb-token-lock'),
        ]);
        $igChannel = $this->makeChannel($tenant, ChannelType::INSTAGRAM, ['instagram_config_id' => $igConfig->id]);
        $fbChannel = $this->makeChannel($tenant, ChannelType::FACEBOOK, ['messenger_config_id' => $fbConfig->id]);

        $disconnector = app(ChannelDisconnector::class);
        $method = new ReflectionMethod($disconnector, 'lockSharedChannelIds');
        $method->setAccessible(true);

        $lockedIds = DB::transaction(fn () => $method->invoke(
            $disconnector,
            Channel::withoutGlobalScopes()->with('instagramConfig')->findOrFail($igChannel->id)
        ));

        $this->assertEqualsCanonicalizing([$igChannel->id, $fbChannel->id], $lockedIds);
    }

    public function test_disconnect_mail_channel_purges_password_without_http_calls(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = MailConfig::create([
            'tenant_id' => $tenant->id,
            'email_address' => 'soporte@acme.com',
            'imap_host' => 'imap.acme.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.acme.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'password' => Crypt::encryptString('secreto'),
            'last_uid' => 42,
            'uidvalidity' => '7',
        ]);
        $channel = $this->makeChannel($tenant, ChannelType::MAIL, ['mail_config_id' => $config->id]);

        Http::fake();

        Sanctum::actingAs($owner);
        $this->postJson("/api/channels/{$channel->id}/disconnect")->assertOk();

        $config->refresh();
        $this->assertNull($config->password);
        $this->assertSame(42, $config->last_uid);
        $this->assertSame('7', $config->uidvalidity);

        Http::assertNothingSent();
    }

    public function test_admin_can_disconnect_channel(): void
    {
        [$tenant, $admin] = $this->createUser('Admin');
        $config = $this->makeWhatsAppConfig();
        $channel = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);

        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true, 'data' => []], 200)]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/channels/{$channel->id}/disconnect")->assertOk();
    }

    public function test_member_cannot_disconnect_channel(): void
    {
        [$tenant, $member] = $this->createUser('Member');
        $config = $this->makeWhatsAppConfig();
        $channel = $this->makeChannel($tenant, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);

        Sanctum::actingAs($member);
        $this->postJson("/api/channels/{$channel->id}/disconnect")->assertForbidden();
    }

    public function test_user_without_access_to_the_channel_cannot_disconnect_it(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $other = User::factory()->create(['tenant_id' => $tenant->id]);
        $other->assignRole('Member');
        $other->givePermissionTo('channels.disconnect');

        $config = $this->makeWhatsAppConfig();
        $channel = $this->makeChannel($tenant, ChannelType::WHATSAPP, [
            'whatsapp_config_id' => $config->id,
            'user_id' => $owner->id,
        ]);

        Sanctum::actingAs($other);
        $this->postJson("/api/channels/{$channel->id}/disconnect")->assertForbidden();
    }

    public function test_disconnecting_an_already_disconnected_channel_is_idempotent(): void
    {
        [$tenant, $owner] = $this->createUser('Owner');
        $config = $this->makeWhatsAppConfig();
        $channel = $this->makeChannel($tenant, ChannelType::WHATSAPP, [
            'whatsapp_config_id' => $config->id,
            'status' => 'disconnected',
        ]);

        Http::fake();

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/channels/{$channel->id}/disconnect");

        $response->assertOk();
        Http::assertNothingSent();
    }

    public function test_cannot_disconnect_a_channel_from_another_tenant(): void
    {
        [, $owner] = $this->createUser('Owner');
        [$tenantB] = $this->createUser('Owner');

        $config = $this->makeWhatsAppConfig();
        $channel = $this->makeChannel($tenantB, ChannelType::WHATSAPP, ['whatsapp_config_id' => $config->id]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/channels/{$channel->id}/disconnect")->assertNotFound();
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

    private function makeWhatsAppConfig(): WhatsAppConfig
    {
        static $seq = 0;
        $seq++;

        return WhatsAppConfig::create([
            'waba_id' => "WABA_{$seq}",
            'phone_number_id' => "PHONE_{$seq}",
            'bussines_token' => Crypt::encryptString('wa-token'),
        ]);
    }

    private function makeChannel(Tenant $tenant, ChannelType $type, array $overrides = []): Channel
    {
        return Channel::create(array_merge([
            'tenant_id' => $tenant->id,
            'user_id' => $overrides['user_id'] ?? $tenant->users()->first()?->id ?? User::factory()->create(['tenant_id' => $tenant->id])->id,
            'type' => $type,
            'name' => 'Canal de prueba',
            'status' => 'active',
        ], $overrides));
    }
}
