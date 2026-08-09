<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessengerConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MessengerSendMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.facebook.app_secret', 'test-app-secret');
        config()->set('services.facebook.graph_version', 'v21.0');
        config()->set('services.facebook.public_media_base_url', 'https://public.example.com');
    }

    public function test_send_text_message_hits_messenger_and_persists_outbound(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/PAGE_1/messages' => Http::response(['message_id' => 'fb_mid_1'], 200),
        ]);

        [$user, $conversation] = $this->createMessengerConversation();
        Sanctum::actingAs($user);

        $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'Hola por Messenger',
        ])->assertStatus(201);

        $message = Message::first();
        $this->assertSame(MessageDirection::OUTBOUND, $message->direction);
        $this->assertSame('fb_mid_1', $message->external_id);
        $this->assertSame('Hola por Messenger', $message->content);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/PAGE_1/messages')
                && $request['recipient']['id'] === 'PSID_1'
                && $request['message']['text'] === 'Hola por Messenger'
                // Ventana estándar de 24h: es lo que corresponde para responder.
                && $request['messaging_type'] === 'RESPONSE'
                // Sin tag: HUMAN_AGENT requiere App Review y no está habilitado.
                && ! isset($request['tag']);
        });
    }

    public function test_send_image_uses_absolute_public_url(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://graph.facebook.com/*/PAGE_1/messages' => Http::response(['message_id' => 'fb_mid_img'], 200),
        ]);

        [$user, $conversation] = $this->createMessengerConversation();
        Sanctum::actingAs($user);

        // multipart real: postJson serializaría el UploadedFile a JSON y la
        // request llegaría sin archivo.
        $this->post('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'image',
            'image' => UploadedFile::fake()->image('foto.jpg'),
        ])->assertStatus(201);

        $this->assertSame(MessageType::Image, Message::first()->message_type);

        Http::assertSent(function ($request) {
            $url = $request['message']['attachment']['payload']['url'] ?? '';

            return $request['message']['attachment']['type'] === 'image'
                && str_starts_with($url, 'https://public.example.com/storage/');
        });
    }

    public function test_sending_from_crm_disables_ai_autoreply(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::response(['message_id' => 'fb_mid_ho'], 200),
        ]);

        [$user, $conversation] = $this->createMessengerConversation();
        $conversation->update(['ai_autoreply_enabled' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'respondo yo',
        ])->assertStatus(201);

        $this->assertFalse((bool) $conversation->refresh()->ai_autoreply_enabled);
    }

    public function test_disconnected_channel_is_rejected(): void
    {
        Http::fake();

        [$user, $conversation, $channel] = $this->createMessengerConversation();
        $channel->update(['status' => 'disconnected']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'x',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('desconectado', $response->json('message'));
        $this->assertSame(0, Message::count());
    }

    public function test_window_closed_error_maps_to_422_with_facebook_name(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::response([
                'error' => ['code' => 10, 'error_subcode' => 2534022, 'message' => 'outside of allowed window'],
            ], 400),
        ]);

        [$user, $conversation] = $this->createMessengerConversation();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'tarde',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ventana de 24 horas', $response->json('message'));
        $this->assertStringContainsString('Facebook', $response->json('message'));
        $this->assertSame(0, Message::count());
    }

    public function test_invalid_token_error_maps_to_422_reconnect(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::response([
                'error' => ['code' => 190, 'type' => 'OAuthException', 'message' => 'Invalid token'],
            ], 400),
        ]);

        [$user, $conversation] = $this->createMessengerConversation();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'x',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Reconectá', $response->json('message'));
        $this->assertStringContainsString('Facebook', $response->json('message'));
    }

    /**
     * Un canal sin transporte de envío debe cortar con 422, no caer a WhatsApp.
     */
    public function test_channel_without_transport_is_rejected(): void
    {
        Http::fake();

        [$user, $conversation, $channel] = $this->createMessengerConversation();
        $channel->update(['type' => ChannelType::TELEGRAM, 'messenger_config_id' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'x',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('no admite el envío', $response->json('message'));
        $this->assertSame(0, Message::count());
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------

    /**
     * @return array{0: User, 1: Conversation, 2: Channel}
     */
    private function createMessengerConversation(): array
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');

        $config = MessengerConfig::create([
            'tenant_id' => $tenant->id,
            'page_id' => 'PAGE_1',
            'page_name' => 'Acme SRL',
            'page_access_token' => Crypt::encryptString('PAGE_TOKEN'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'messenger_config_id' => $config->id,
            'type' => ChannelType::FACEBOOK,
            'external_id' => 'PAGE_1',
            'name' => 'Acme SRL',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fulano FB',
            'source' => 'facebook',
            'external_id' => 'PSID_1',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        return [$user, $conversation, $channel];
    }

    private function seedTenantWithRoles(): Tenant
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        return $tenant;
    }
}
