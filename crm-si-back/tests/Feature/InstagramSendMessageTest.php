<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsInstagramConversation;
use Tests\TestCase;

class InstagramSendMessageTest extends TestCase
{
    use BuildsInstagramConversation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.facebook.app_secret', 'test-app-secret');
        config()->set('services.facebook.graph_version', 'v21.0');
        config()->set('services.facebook.public_media_base_url', 'https://public.example.com');
    }

    public function test_send_text_message_hits_instagram_and_persists_outbound(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/PAGE_1/messages' => Http::response(['message_id' => 'ig_mid_1'], 200),
        ]);

        [$user, $conversation] = $this->createInstagramConversation();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'Hola por IG',
        ]);

        $response->assertStatus(201);

        $message = Message::outbound()->first();
        $this->assertSame(MessageDirection::OUTBOUND, $message->direction);
        $this->assertSame('ig_mid_1', $message->external_id);
        $this->assertSame('Hola por IG', $message->content);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/PAGE_1/messages')
                && $request['recipient']['id'] === 'IGSID_1'
                && $request['message']['text'] === 'Hola por IG';
        });
    }

    public function test_window_closed_error_maps_to_422(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::response([
                'error' => ['code' => 10, 'error_subcode' => 2534022, 'message' => 'outside of allowed window'],
            ], 400),
        ]);

        [$user, $conversation] = $this->createInstagramConversation();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'tarde',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ventana de 24 horas', $response->json('message'));
        $this->assertSame(0, Message::outbound()->count());
    }

    public function test_closed_window_with_oauth_type_is_classified_as_window_not_token(): void
    {
        // Meta puede reportar la ventana cerrada con code 10/subcode 2534022 y
        // además type=OAuthException. Debe ganar la ventana, no el token.
        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::response([
                'error' => [
                    'code' => 10,
                    'error_subcode' => 2534022,
                    'type' => 'OAuthException',
                    'message' => 'This message is sent outside of allowed window.',
                ],
            ], 400),
        ]);

        [$user, $conversation] = $this->createInstagramConversation();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'tarde',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ventana de 24 horas', $response->json('message'));
    }

    public function test_invalid_token_error_maps_to_422_reconnect(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::response([
                'error' => ['code' => 190, 'type' => 'OAuthException', 'message' => 'Invalid token'],
            ], 400),
        ]);

        [$user, $conversation] = $this->createInstagramConversation();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'x',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Reconectá', $response->json('message'));
    }

    public function test_ogg_audio_is_rejected_for_instagram(): void
    {
        [$user, $conversation] = $this->createInstagramConversation();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'audio',
            'audio' => UploadedFile::fake()->createWithContent('audio.ogg', 'data'),
        ], ['Content-Type' => 'multipart/form-data']);

        // Puede fallar en la validación mimetypes general (ogg permitido) y luego
        // en el guard de canal IG. En cualquier caso, 422 y sin envío.
        $response->assertStatus(422);
    }

    public function test_template_send_is_blocked_on_instagram_channel(): void
    {
        [$user, $conversation] = $this->createInstagramConversation();
        Sanctum::actingAs($user);

        // Un template válido (existe) para que la validación pase y se alcance el
        // guard de tipo de canal.
        $waConfig = WhatsAppConfig::create([
            'phone_number_id' => 'PN_1',
            'waba_id' => 'WABA_1',
            'bussines_token' => Crypt::encryptString('t'),
        ]);
        $template = WhatsAppTemplate::create([
            'tenant_id' => $conversation->tenant_id,
            'whatsapp_config_id' => $waConfig->id,
            'external_id' => '1234567890',
            'name' => 'test_template',
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [['type' => 'BODY', 'text' => 'Hola']],
            'synced_at' => now(),
        ]);

        $response = $this->postJson("/api/conversations/{$conversation->id}/send-template", [
            'template_id' => $template->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('WhatsApp', $response->json('message'));
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
