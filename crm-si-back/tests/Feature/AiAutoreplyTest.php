<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\ChannelType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateAiReplyJob;
use App\Models\AiConfig;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Services\AiReplyService;
use App\Services\InstagramMessageService;
use App\Services\MessengerMessageService;
use App\Services\MailMessageService;
use App\Services\WhatsAppMessageService;
use App\Services\WhatsAppTemplateService;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AiAutoreplyTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = 'PHONE_TEST_1';

    protected function setUp(): void
    {
        parent::setUp();

        // El lock de unicidad de GenerateAiReplyJob usa el cache default.
        // En el contenedor, las env vars de Docker ($_SERVER) pisan el force
        // de phpunit.xml y el lock iría a Redis real, quedando huérfano entre
        // corridas (Queue::fake nunca lo libera). Forzar array lo aísla.
        config(['cache.default' => 'array']);
    }

    public function test_inbound_text_dispatches_ai_reply_job(): void
    {
        Queue::fake();

        // Tenant con AiConfig habilitada + key propia (BYOK).
        $this->createChannelSetup(aiDefault: true);

        app(WhatsAppMessageService::class)->processIncomingMessage($this->inboundTextPayload('Hola, ¿tienen stock?'));

        $conversation = Conversation::withoutGlobalScopes()->firstOrFail();
        $this->assertTrue($conversation->ai_autoreply_enabled);

        Queue::assertPushed(GenerateAiReplyJob::class, function (GenerateAiReplyJob $job) use ($conversation) {
            return $job->conversationId === $conversation->id;
        });
    }

    public function test_inbound_image_dispatches_ai_reply_job(): void
    {
        // La descarga de media pega a graph.facebook.com (metadata + binario);
        // se fakea para que el webhook de imagen persista y dispare el job.
        Storage::fake('public');
        Http::fake([
            'graph.facebook.com/v21.0/MEDIA_TEST_1' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/img.png',
                'mime_type' => 'image/png',
            ], 200),
            'lookaside.fbsbx.com/*' => Http::response('fake-binary', 200),
        ]);
        Queue::fake();

        $this->createChannelSetup(aiDefault: true);

        app(WhatsAppMessageService::class)->processIncomingMessage($this->inboundImagePayload());

        $conversation = Conversation::withoutGlobalScopes()->firstOrFail();

        Queue::assertPushed(GenerateAiReplyJob::class, function (GenerateAiReplyJob $job) use ($conversation) {
            return $job->conversationId === $conversation->id;
        });
    }

    public function test_inbound_text_does_not_dispatch_when_channel_default_off(): void
    {
        Queue::fake();

        $this->createChannelSetup(aiDefault: false);

        app(WhatsAppMessageService::class)->processIncomingMessage($this->inboundTextPayload('Hola'));

        $this->assertFalse(Conversation::withoutGlobalScopes()->firstOrFail()->ai_autoreply_enabled);
        Queue::assertNotPushed(GenerateAiReplyJob::class);
    }

    public function test_inbound_text_does_not_dispatch_when_tenant_has_no_ai_config(): void
    {
        Queue::fake();

        // Sin AiConfig del tenant (BYOK): aunque la conversación quede con
        // ai_autoreply activo, no se despacha el job.
        $this->createChannelSetup(aiDefault: true, withAiConfig: false);

        app(WhatsAppMessageService::class)->processIncomingMessage($this->inboundTextPayload('Hola'));

        Queue::assertNotPushed(GenerateAiReplyJob::class);
    }

    public function test_human_reply_disables_autoreply_handoff(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HUMAN']]], 200),
        ]);
        Queue::fake();

        [$user, $conversation] = $this->createConversationSetup(aiEnabled: true);

        app(WhatsAppMessageService::class)->sendTextMessageFromCRM($conversation, 'Respuesta humana', $user);

        $this->assertFalse($conversation->fresh()->ai_autoreply_enabled);
    }

    public function test_template_message_disables_autoreply_handoff(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEMPLATE']]], 200),
        ]);

        [$user, $conversation] = $this->createConversationSetup(aiEnabled: true);

        // WhatsAppConfig no tiene HasFactory, así que no se puede usar la
        // factory de template (referencia WhatsAppConfig::factory()). Se crea
        // el modelo directo con la config del canal ya existente.
        $template = WhatsAppTemplate::create([
            'tenant_id' => $conversation->tenant_id,
            'whatsapp_config_id' => $conversation->channel->whatsapp_config_id,
            'external_id' => '9999999999',
            'name' => 'pedido_listo',
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [
                ['type' => 'BODY', 'text' => 'Hola {{1}}, tu pedido está listo.'],
            ],
            'synced_at' => now(),
        ]);

        app(WhatsAppTemplateService::class)->sendTemplateMessage(
            $conversation,
            $template,
            [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Juan']]]],
            $user,
        );

        $this->assertFalse($conversation->fresh()->ai_autoreply_enabled);
    }

    public function test_smb_echo_from_human_disables_autoreply_handoff(): void
    {
        [, $conversation] = $this->createConversationSetup(aiEnabled: true);

        // El eco resuelve el contacto por teléfono exacto (findOrCreateContact),
        // así que el `to` del eco debe coincidir con el phone del contacto del
        // setup para caer sobre la misma conversación.
        $echoTo = $conversation->contact->phone;

        // Eco de un mensaje enviado por un humano desde el WhatsApp Business App.
        // Debe apagar el bot igual que los send*FromCRM.
        app(WhatsAppMessageService::class)->processSmbMessageEchoes([
            'value' => [
                'metadata' => [
                    'phone_number_id' => self::PHONE_NUMBER_ID,
                ],
                'message_echoes' => [
                    [
                        'id' => 'wamid.ECHO_'.uniqid(),
                        'to' => $echoTo,
                        'timestamp' => (string) now()->timestamp,
                        'type' => 'text',
                        'text' => ['body' => 'Respuesta desde la app'],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($conversation->fresh()->ai_autoreply_enabled);
    }

    public function test_job_sends_system_message_with_ai_reply(): void
    {
        [, $conversation] = $this->createConversationSetup(aiEnabled: true);

        $this->mock(AiReplyService::class, function ($mock) use ($conversation) {
            $mock->shouldReceive('respond')
                ->once()
                ->withArgs(fn (Conversation $c, AiConfig $config) => $c->id === $conversation->id
                    && $config->tenant_id === $conversation->tenant_id)
                ->andReturn('Hola, sí tenemos stock.');
        });

        $this->mock(WhatsAppMessageService::class, function ($mock) use ($conversation) {
            $mock->shouldReceive('sendSystemTextMessageFromCRM')
                ->once()
                ->withArgs(fn (Conversation $c, string $content) => $c->id === $conversation->id
                    && $content === 'Hola, sí tenemos stock.');
        });

        (new GenerateAiReplyJob($conversation->id))->handle(
            app(AiReplyService::class),
            app(WhatsAppMessageService::class),
            app(InstagramMessageService::class),
            app(MessengerMessageService::class),
            app(MailMessageService::class),
        );
    }

    /**
     * El transporte se elige por tipo de canal. Antes había un if/else con
     * default WhatsApp: una conversación de Messenger salía por WhatsApp.
     */
    public function test_job_sends_via_messenger_for_facebook_channel(): void
    {
        [, $conversation] = $this->createConversationSetup(aiEnabled: true);
        $conversation->channel->update(['type' => ChannelType::FACEBOOK]);

        $this->mock(AiReplyService::class, function ($mock) {
            $mock->shouldReceive('respond')->once()->andReturn('Respuesta IA');
        });

        $this->mock(MessengerMessageService::class, function ($mock) use ($conversation) {
            $mock->shouldReceive('sendSystemTextMessageFromCRM')
                ->once()
                ->withArgs(fn (Conversation $c, string $content) => $c->id === $conversation->id
                    && $content === 'Respuesta IA');
        });

        $this->mock(WhatsAppMessageService::class, function ($mock) {
            $mock->shouldNotReceive('sendSystemTextMessageFromCRM');
        });

        $this->mock(InstagramMessageService::class, function ($mock) {
            $mock->shouldNotReceive('sendSystemTextMessageFromCRM');
        });

        (new GenerateAiReplyJob($conversation->id))->handle(
            app(AiReplyService::class),
            app(WhatsAppMessageService::class),
            app(InstagramMessageService::class),
            app(MessengerMessageService::class),
            app(MailMessageService::class),
        );
    }

    /**
     * Un canal sin transporte de IA no debe caer a WhatsApp: aborta y loguea.
     */
    public function test_job_aborts_for_channel_without_transport(): void
    {
        [, $conversation] = $this->createConversationSetup(aiEnabled: true);
        $conversation->channel->update(['type' => ChannelType::TELEGRAM]);

        $this->mock(AiReplyService::class, function ($mock) {
            $mock->shouldReceive('respond')->once()->andReturn('Respuesta IA');
        });

        foreach ([WhatsAppMessageService::class, InstagramMessageService::class, MessengerMessageService::class, MailMessageService::class] as $service) {
            $this->mock($service, function ($mock) {
                $mock->shouldNotReceive('sendSystemTextMessageFromCRM');
            });
        }

        (new GenerateAiReplyJob($conversation->id))->handle(
            app(AiReplyService::class),
            app(WhatsAppMessageService::class),
            app(InstagramMessageService::class),
            app(MessengerMessageService::class),
            app(MailMessageService::class),
        );
    }

    public function test_job_skips_when_autoreply_was_turned_off(): void
    {
        [, $conversation] = $this->createConversationSetup(aiEnabled: false);

        $this->mock(AiReplyService::class, function ($mock) {
            $mock->shouldNotReceive('respond');
        });

        $this->mock(WhatsAppMessageService::class, function ($mock) {
            $mock->shouldNotReceive('sendSystemTextMessageFromCRM');
        });

        (new GenerateAiReplyJob($conversation->id))->handle(
            app(AiReplyService::class),
            app(WhatsAppMessageService::class),
            app(InstagramMessageService::class),
            app(MessengerMessageService::class),
            app(MailMessageService::class),
        );
    }

    public function test_job_skips_when_ai_config_disabled(): void
    {
        // Conversación con autoreply activo pero AiConfig del tenant deshabilitada:
        // gate BYOK → no responde.
        [, $conversation] = $this->createConversationSetup(aiEnabled: true);
        AiConfig::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->update(['enabled' => false]);

        $this->mock(AiReplyService::class, function ($mock) {
            $mock->shouldNotReceive('respond');
        });

        $this->mock(WhatsAppMessageService::class, function ($mock) {
            $mock->shouldNotReceive('sendSystemTextMessageFromCRM');
        });

        (new GenerateAiReplyJob($conversation->id))->handle(
            app(AiReplyService::class),
            app(WhatsAppMessageService::class),
            app(InstagramMessageService::class),
            app(MessengerMessageService::class),
            app(MailMessageService::class),
        );
    }

    public function test_toggle_endpoint_updates_conversation(): void
    {
        [$user, $conversation] = $this->createConversationSetup(aiEnabled: false);

        Sanctum::actingAs($user);

        $this->patchJson("/api/conversations/{$conversation->id}/ai-autoreply", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.ai_autoreply_enabled', true);

        $this->assertTrue($conversation->fresh()->ai_autoreply_enabled);

        $this->patchJson("/api/conversations/{$conversation->id}/ai-autoreply", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.ai_autoreply_enabled', false);

        $this->assertFalse($conversation->fresh()->ai_autoreply_enabled);
    }

    public function test_conversation_list_includes_autoreply_flag(): void
    {
        [$user, $conversation] = $this->createConversationSetup(aiEnabled: true);

        Sanctum::actingAs($user);

        // El listado arma la respuesta a mano: el flag debe venir para que el
        // switch de la UI no aparezca apagado tras un reload/cambio de canal.
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.ai_autoreply_enabled', true);
    }

    /**
     * Tenant + config + canal WhatsApp listos para recibir webhooks.
     *
     * @return array{0: User, 1: Channel, 2: WhatsAppConfig}
     */
    private function createChannelSetup(bool $aiDefault, bool $withAiConfig = true): array
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

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        $user->assignRole('Owner');

        $config = WhatsAppConfig::create([
            'phone_number_id' => self::PHONE_NUMBER_ID,
            'display_phone_number' => '+54911999',
            'waba_id' => 'WABA_TEST',
            'bussines_token' => Crypt::encryptString('token-test'),
            'ai_autoreply_default' => $aiDefault,
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Main channel',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);

        // Config de IA del tenant con key propia habilitada (BYOK).
        if ($withAiConfig) {
            $aiConfig = AiConfig::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'provider' => AiProvider::CLAUDE,
                'model' => 'claude-opus-4-8',
                'enabled' => true,
            ]);
            $aiConfig->setEncryptedApiKey('sk-test-key');
        }

        return [$user, $channel, $config];
    }

    /**
     * Setup completo con conversación ya creada.
     *
     * @return array{0: User, 1: Conversation}
     */
    private function createConversationSetup(bool $aiEnabled): array
    {
        [$user, $channel] = $this->createChannelSetup(aiDefault: false);

        $contact = Contact::create([
            'tenant_id' => $channel->tenant_id,
            'name' => 'Jane Doe',
            'phone' => '+5491111111111',
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'ai_autoreply_enabled' => $aiEnabled,
        ]);

        return [$user, $conversation];
    }

    /**
     * Payload mínimo de webhook de Meta para un mensaje de texto entrante.
     *
     * @return array<string, mixed>
     */
    private function inboundTextPayload(string $body): array
    {
        return [
            'value' => [
                'metadata' => [
                    'phone_number_id' => self::PHONE_NUMBER_ID,
                ],
                'contacts' => [
                    ['profile' => ['name' => 'Jane Doe'], 'wa_id' => '5491111111111'],
                ],
                'messages' => [
                    [
                        'id' => 'wamid.TEST_'.uniqid(),
                        'from' => '5491111111111',
                        'timestamp' => (string) now()->timestamp,
                        'type' => 'text',
                        'text' => ['body' => $body],
                    ],
                ],
            ],
        ];
    }

    /**
     * Payload de webhook de Meta para un mensaje de imagen entrante.
     *
     * @return array<string, mixed>
     */
    private function inboundImagePayload(): array
    {
        return [
            'value' => [
                'metadata' => [
                    'phone_number_id' => self::PHONE_NUMBER_ID,
                ],
                'contacts' => [
                    ['profile' => ['name' => 'Jane Doe'], 'wa_id' => '5491111111111'],
                ],
                'messages' => [
                    [
                        'id' => 'wamid.IMG_'.uniqid(),
                        'from' => '5491111111111',
                        'timestamp' => (string) now()->timestamp,
                        'type' => 'image',
                        'image' => [
                            'id' => 'MEDIA_TEST_1',
                            'mime_type' => 'image/png',
                            'caption' => 'Mirá esta foto',
                        ],
                    ],
                ],
            ],
        ];
    }
}
