<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Enums\WhatsAppFlowStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppTemplate;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WhatsAppFlowManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Channel $channel;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme']);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $this->user->assignRole('Owner');

        $config = WhatsAppConfig::create([
            'phone_number_id' => '123456789',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);
        $this->channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $this->user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);
        $this->conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $this->channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        Sanctum::actingAs($this->user);
        Http::preventStrayRequests();
    }

    public function test_creating_a_flow_from_json_stores_the_meta_flow_as_draft(): void
    {
        $this->fakeGraphFlow('flow-1');

        $this->postJson("/api/channels/{$this->channel->id}/flows", [
            'name' => 'Alta',
            'categories' => ['SIGN_UP'],
            'flow_json' => json_encode(['version' => '7.3', 'screens' => [['id' => 'DATOS']]]),
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT');

        $this->assertDatabaseHas('whatsapp_flows', [
            'whatsapp_config_id' => $this->channel->whatsapp_config_id,
            'meta_flow_id' => 'flow-1',
            'status' => 'DRAFT',
        ]);
    }

    public function test_creating_a_flow_from_the_builder_compiles_it_and_keeps_the_definition(): void
    {
        $this->fakeGraphFlow('flow-1');
        $definition = $this->definition([
            ['type' => 'short_text', 'key' => 'nombre', 'label' => 'Nombre', 'input_type' => 'text', 'required' => true, 'maps_to' => 'contact.name'],
        ]);

        $this->postJson("/api/channels/{$this->channel->id}/flows", [
            'name' => 'Alta',
            'categories' => ['LEAD_GENERATION'],
            'builder_definition' => $definition,
        ])->assertCreated()->assertJsonPath('data.has_builder_definition', true);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/waba-test/flows')
            && json_decode($request['flow_json'], true)['version'] === '7.3');

        $flow = WhatsAppFlow::sole();
        $this->assertSame($definition, $flow->builder_definition);
        $this->assertSame([['response_key' => 'nombre', 'target' => 'contact.name']], $flow->field_mappings);
    }

    public function test_builder_errors_are_reported_per_block(): void
    {
        Http::fake();
        $block = ['type' => 'short_text', 'key' => 'nombre', 'label' => 'Nombre', 'input_type' => 'text', 'required' => true];

        $this->postJson("/api/channels/{$this->channel->id}/flows", [
            'name' => 'Alta',
            'categories' => ['OTHER'],
            'builder_definition' => $this->definition([$block, $block]),
        ])->assertStatus(422)->assertJsonValidationErrors(['builder_definition.screens.0.blocks.1.key']);

        Http::assertNothingSent();
    }

    public function test_sending_a_flow_from_the_chat_opens_a_session_and_sends_its_token(): void
    {
        $this->inboundMessage();
        $flow = $this->publishedFlow('flow-1');
        Http::fake(['https://graph.facebook.com/*/123456789/messages' => Http::response(['messages' => [['id' => 'wamid.flow-1']]])]);

        $this->postJson("/api/conversations/{$this->conversation->id}/send-flow", [
            'whatsapp_flow_id' => $flow->id,
            'body' => 'Completá tus datos',
            'cta' => 'Completar',
        ])->assertCreated()->assertJsonPath('data.message_type', 'flow');

        $session = WhatsAppFlowSession::sole();
        $this->assertSame(Message::where('external_id', 'wamid.flow-1')->value('id'), $session->message_id);
        Http::assertSent(fn (Request $request) => ($request['interactive']['action']['parameters']['flow_token'] ?? null) === $session->flow_token
            && $request['interactive']['action']['parameters']['flow_action'] === 'navigate'
            && $request['interactive']['action']['parameters']['flow_action_payload']['screen'] === 'DATOS');
    }

    public function test_resending_a_flow_resumes_the_unfinished_answers_of_the_contact(): void
    {
        $this->inboundMessage();
        $flow = $this->publishedFlow('flow-1');
        $previous = WhatsAppFlowSession::create([
            'tenant_id' => $this->conversation->tenant_id,
            'whatsapp_flow_id' => $flow->id,
            'conversation_id' => $this->conversation->id,
            'contact_id' => $this->conversation->contact_id,
            'flow_token' => 'token-previo',
            'status' => WhatsAppFlowSession::STATUS_PENDING,
            'data' => ['correo' => 'ana@x.com'],
            'expires_at' => now()->subDay(),
        ]);
        Http::fake(['https://graph.facebook.com/*/123456789/messages' => Http::response(['messages' => [['id' => 'wamid.flow-2']]])]);

        $this->postJson("/api/conversations/{$this->conversation->id}/send-flow", [
            'whatsapp_flow_id' => $flow->id,
            'body' => 'Seguí donde quedaste',
            'cta' => 'Completar',
        ])->assertCreated();

        $session = WhatsAppFlowSession::whereKeyNot($previous->id)->sole();
        $this->assertSame(['correo' => 'ana@x.com'], $session->data);
    }

    public function test_sending_a_template_with_a_flow_button_injects_the_session_token(): void
    {
        $this->publishedFlow('flow-1');
        $template = WhatsAppTemplate::create([
            'tenant_id' => $this->channel->tenant_id,
            'whatsapp_config_id' => $this->channel->whatsapp_config_id,
            'external_id' => 'tpl-1',
            'name' => 'alta',
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [
                ['type' => 'BODY', 'text' => 'Completá el alta'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Web', 'url' => 'https://example.com'],
                    ['type' => 'FLOW', 'text' => 'Abrir', 'flow_id' => 'flow-1', 'flow_action' => 'NAVIGATE'],
                ]],
            ],
        ]);
        Http::fake(['https://graph.facebook.com/*/123456789/messages' => Http::response(['messages' => [['id' => 'wamid.tpl-1']]])]);

        $this->postJson("/api/conversations/{$this->conversation->id}/send-template", [
            'template_id' => $template->id,
            'components' => [],
        ])->assertCreated();

        $session = WhatsAppFlowSession::sole();
        $this->assertSame(Message::where('external_id', 'wamid.tpl-1')->value('id'), $session->message_id);
        Http::assertSent(fn (Request $request) => collect($request['template']['components'] ?? [])->contains(
            fn ($component) => ($component['sub_type'] ?? null) === 'flow'
                && $component['index'] === '1'
                && $component['parameters'][0]['action']['flow_token'] === $session->flow_token
                && $component['parameters'][0]['action']['flow_action_data'] === ['contact_name' => 'Jane Doe'],
        ));
    }

    public function test_send_defaults_are_saved_locally_without_touching_meta(): void
    {
        Http::fake();
        $flow = $this->publishedFlow('flow-1');

        $this->getJson("/api/whatsapp-flows/{$flow->id}")
            ->assertJsonPath('data.send_defaults.body', WhatsAppFlow::DEFAULT_SEND_BODY)
            ->assertJsonPath('data.send_defaults.cta', WhatsAppFlow::DEFAULT_SEND_CTA);

        $this->putJson("/api/whatsapp-flows/{$flow->id}", [
            'send_defaults' => ['header' => null, 'body' => 'Completá tus datos', 'footer' => null, 'cta' => 'Abrir'],
        ])->assertOk()
            ->assertJsonPath('data.send_defaults.body', 'Completá tus datos')
            ->assertJsonPath('data.send_defaults.cta', 'Abrir')
            ->assertJsonPath('data.status', 'PUBLISHED');

        Http::assertNothingSent();
    }

    private function fakeGraphFlow(string $metaFlowId): void
    {
        Http::fake([
            'https://graph.facebook.com/*/waba-test/flows' => Http::response(['id' => $metaFlowId, 'success' => true]),
            'https://graph.facebook.com/*/123456789/whatsapp_business_encryption' => Http::response(['success' => true]),
            "https://graph.facebook.com/*/{$metaFlowId}/assets*" => Http::response(['data' => []]),
            "https://graph.facebook.com/*/{$metaFlowId}*" => Http::response([
                'id' => $metaFlowId,
                'name' => 'Alta',
                'status' => 'DRAFT',
                'categories' => ['SIGN_UP'],
                'validation_errors' => [],
                'json_version' => '7.3',
            ]),
        ]);
    }

    private function definition(array $blocks): array
    {
        return [
            'schema' => 1,
            'screens' => [['title' => 'Tus datos', 'footer_label' => 'Enviar', 'blocks' => $blocks]],
            'appointment' => null,
        ];
    }

    private function publishedFlow(string $metaFlowId): WhatsAppFlow
    {
        return WhatsAppFlow::create([
            'tenant_id' => $this->channel->tenant_id,
            'whatsapp_config_id' => $this->channel->whatsapp_config_id,
            'meta_flow_id' => $metaFlowId,
            'name' => 'Alta',
            'status' => WhatsAppFlowStatus::Published,
            'published_at' => now(),
            'categories' => ['SIGN_UP'],
            'flow_json' => json_encode(['version' => '7.3', 'screens' => [[
                'id' => 'DATOS',
                'data' => ['contact_name' => ['type' => 'string', '__example__' => '']],
            ]]]),
        ]);
    }

    private function inboundMessage(): void
    {
        Message::create([
            'tenant_id' => $this->conversation->tenant_id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $this->conversation->contact_id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'wamid.in-1',
        ]);
    }
}
