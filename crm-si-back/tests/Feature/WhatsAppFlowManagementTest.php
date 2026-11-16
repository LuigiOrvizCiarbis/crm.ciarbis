<?php

namespace Tests\Feature;

use App\Automation\Exceptions\ActionSkippedException;
use App\Automation\Handlers\WhatsAppFlowActionHandler;
use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Enums\WhatsAppFlowStatus;
use App\Models\AutomationAction;
use App\Models\AutomationRun;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MediaAsset;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppTemplate;
use App\Services\Flows\Builder\FlowDefinitionCompiler;
use App\Services\Flows\WhatsAppFlowSessionService;
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

    public function test_a_name_already_used_in_the_whatsapp_account_is_rejected_before_and_after_meta(): void
    {
        // Uno creado en WhatsApp Manager y sin sincronizar sólo lo conoce Meta.
        Http::fake(['https://graph.facebook.com/*/waba-test/flows' => Http::response(['error' => [
            'code' => 100,
            'message' => 'Invalid parameter',
            'error_user_msg' => 'Flow name should be unique within one WhatsApp Business Account. Please select another name for your Flow.',
        ]], 400)]);
        $existing = $this->publishedFlow('flow-1');
        // Otro número de la misma cuenta (WABA): Meta exige nombres únicos en toda la cuenta.
        $sibling = WhatsAppConfig::create(['phone_number_id' => '555', 'waba_id' => 'waba-test', 'bussines_token' => Crypt::encryptString('t')]);
        $siblingChannel = Channel::create([...$this->channel->only(['tenant_id', 'user_id', 'type', 'status']), 'name' => 'Otro número', 'whatsapp_config_id' => $sibling->id]);
        $create = fn (Channel $channel, string $name) => $this->postJson("/api/channels/{$channel->id}/flows", [
            'name' => $name,
            'categories' => ['OTHER'],
            'flow_json' => json_encode(['version' => '7.3', 'screens' => [['id' => 'DATOS']]]),
        ]);

        $create($siblingChannel, 'Alta')->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->postJson("/api/whatsapp-flows/{$existing->id}/clone", ['name' => 'Alta'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        Http::assertNothingSent();

        // Sin otro formulario local con ese nombre, el rechazo llega de Meta y se marca igual en el campo.
        $create($this->channel, 'Encuesta')->assertUnprocessable()->assertJsonValidationErrors(['name']);
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

    public function test_resending_a_builder_flow_opens_with_the_unfinished_answers_and_closes_the_previous_send(): void
    {
        $this->inboundMessage();
        $definition = $this->definition([
            ['type' => 'short_text', 'key' => 'correo', 'label' => 'Email', 'input_type' => 'email', 'required' => true],
        ]);
        $flow = $this->publishedFlow('flow-1');
        $flow->update([
            'builder_definition' => $definition,
            'flow_json' => json_encode((new FlowDefinitionCompiler)->compile($definition)->flowJson),
        ]);
        $previous = WhatsAppFlowSession::create([
            'tenant_id' => $this->conversation->tenant_id,
            'whatsapp_flow_id' => $flow->id,
            'conversation_id' => $this->conversation->id,
            'contact_id' => $this->conversation->contact_id,
            'flow_token' => 'token-previo',
            'status' => WhatsAppFlowSession::STATUS_PENDING,
            'data' => ['correo' => 'ana@x.com'],
            'expires_at' => now()->addDay(),
        ]);
        // Un envío vencido no se retoma aunque sea más nuevo.
        WhatsAppFlowSession::create([
            'tenant_id' => $this->conversation->tenant_id,
            'whatsapp_flow_id' => $flow->id,
            'conversation_id' => $this->conversation->id,
            'contact_id' => $this->conversation->contact_id,
            'flow_token' => 'token-vencido',
            'status' => WhatsAppFlowSession::STATUS_PENDING,
            'data' => ['correo' => 'viejo@x.com'],
            'expires_at' => now()->subDay(),
        ]);
        Http::fake(['https://graph.facebook.com/*/123456789/messages' => Http::response(['messages' => [['id' => 'wamid.flow-2']]])]);

        $this->postJson("/api/conversations/{$this->conversation->id}/send-flow", [
            'whatsapp_flow_id' => $flow->id,
            'body' => 'Seguí donde quedaste',
            'cta' => 'Completar',
        ])->assertCreated();

        $session = WhatsAppFlowSession::latest('id')->first();
        $this->assertSame(['correo' => 'ana@x.com'], $session->data);
        $this->assertSame(WhatsAppFlowSession::STATUS_SUPERSEDED, $previous->fresh()->status);
        // Se abre sin consultar al CRM: la primera pantalla y sus datos viajan en el envío.
        Http::assertSent(fn (Request $request) => ($request['interactive']['action']['parameters']['flow_action'] ?? null) === 'navigate'
            && $request['interactive']['action']['parameters']['flow_action_payload'] === ['screen' => 'PANTALLA_A', 'data' => ['correo' => 'ana@x.com']]);
    }

    public function test_customers_get_the_published_version_until_the_draft_is_published(): void
    {
        $this->inboundMessage();
        $published = $this->definition([
            ['type' => 'short_text', 'key' => 'nombre', 'label' => 'Nombre', 'input_type' => 'text', 'required' => true, 'prefill' => 'contact_name'],
        ]);
        $flow = WhatsAppFlow::create([
            'tenant_id' => $this->channel->tenant_id,
            'whatsapp_config_id' => $this->channel->whatsapp_config_id,
            'meta_flow_id' => 'flow-1',
            'name' => 'Alta',
            'status' => WhatsAppFlowStatus::Draft,
            'categories' => ['SIGN_UP'],
            'builder_definition' => $published,
            'flow_json' => json_encode((new FlowDefinitionCompiler)->compile($published)->flowJson),
        ]);
        Http::fake([
            'https://graph.facebook.com/*/123456789/messages' => Http::response(['messages' => [['id' => 'wamid.flow-1']]]),
            'https://graph.facebook.com/*/flow-1/publish' => Http::response(['success' => true]),
            'https://graph.facebook.com/*/flow-1*' => Http::response([
                'id' => 'flow-1', 'name' => 'Alta', 'status' => 'PUBLISHED', 'categories' => ['SIGN_UP'], 'validation_errors' => [], 'json_version' => '7.3',
            ]),
        ]);
        $this->postJson("/api/whatsapp-flows/{$flow->id}/publish")->assertOk();

        // Cambios guardados después de publicar: Meta los deja en borrador.
        $draft = $this->definition([
            ['type' => 'short_text', 'key' => 'empresa', 'label' => 'Empresa', 'input_type' => 'text', 'required' => true],
        ]);
        $flow->refresh()->update([
            'status' => WhatsAppFlowStatus::Draft,
            'builder_definition' => $draft,
            'flow_json' => json_encode((new FlowDefinitionCompiler)->compile($draft)->flowJson),
        ]);

        $this->postJson("/api/conversations/{$this->conversation->id}/send-flow", [
            'whatsapp_flow_id' => $flow->id,
            'body' => 'Completá tus datos',
            'cta' => 'Completar',
        ])->assertCreated();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/messages')
            && ! isset($request['interactive']['action']['parameters']['mode'])
            && $request['interactive']['action']['parameters']['flow_action_payload'] === ['screen' => 'PANTALLA_A', 'data' => ['nombre' => 'Jane Doe']]);
    }

    public function test_overlapping_sends_keep_the_newest_open_whichever_finishes_first(): void
    {
        $flow = $this->publishedFlow('flow-1');
        $sessions = app(WhatsAppFlowSessionService::class);
        $first = $sessions->open($flow, $this->conversation);
        $second = $sessions->open($flow, $this->conversation);

        // El segundo envío termina antes que el primero.
        $sessions->activate($second, $this->flowMessage('wamid.flow-2'));
        $sessions->activate($first, $firstMessage = $this->flowMessage('wamid.flow-1'));

        $this->assertSame(WhatsAppFlowSession::STATUS_PENDING, $second->fresh()->status);
        $this->assertSame(WhatsAppFlowSession::STATUS_SUPERSEDED, $first->fresh()->status);
        $this->assertSame(WhatsAppFlowSession::STATUS_SUPERSEDED, $firstMessage->fresh()->interactive['session_status']);
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

    public function test_opening_template_variables_are_checked_against_the_template_before_saving(): void
    {
        Http::fake();
        $flow = $this->publishedFlow('flow-1');
        $template = $this->openingTemplate('tpl-1', 'Hola {{nombre}}, completá el alta');

        $this->getJson("/api/whatsapp-flows/{$flow->id}")
            ->assertJsonPath('data.send_templates.0.id', $template->id)
            ->assertJsonPath('data.send_templates.0.issue', 'parameters')
            ->assertJsonPath('data.effective_template_id', null);

        $this->putJson("/api/whatsapp-flows/{$flow->id}", [
            'opening' => ['default_template_id' => $template->id, 'templates' => [$template->id => ['parameters' => []]]],
        ])->assertUnprocessable()->assertJsonValidationErrors(["opening.templates.{$template->id}.parameters"]);

        $this->putJson("/api/whatsapp-flows/{$flow->id}", [
            'opening' => ['default_template_id' => $template->id, 'templates' => [$template->id => ['parameters' => [
                ['component' => 'body', 'name' => 'nombre', 'source' => 'field', 'path' => 'contact.name', 'fallback' => 'Hola'],
            ]]]],
        ])->assertOk()
            ->assertJsonPath('data.send_templates.0.ready', true)
            ->assertJsonPath('data.effective_template_id', $template->id);

        Http::assertNothingSent();
    }

    public function test_a_default_template_that_no_longer_opens_the_flow_does_not_block_saving(): void
    {
        Http::fake();
        $flow = $this->publishedFlow('flow-1');
        $stale = $this->openingTemplate('tpl-viejo', 'Completá el alta');
        $flow->update(['opening' => ['default_template_id' => $stale->id, 'templates' => []]]);
        $stale->delete();
        $current = $this->openingTemplate('tpl-nuevo', 'Completá el alta');

        $this->putJson("/api/whatsapp-flows/{$flow->id}", [
            'opening' => ['templates' => [$current->id => ['parameters' => []]]],
        ])->assertOk()
            ->assertJsonPath('data.opening.default_template_id', null)
            ->assertJsonPath('data.effective_template_id', $current->id);

        $this->putJson("/api/whatsapp-flows/{$flow->id}", [
            'opening' => ['default_template_id' => $stale->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['opening.default_template_id']);

        // Un objeto vacío no cambia nada.
        $this->putJson("/api/whatsapp-flows/{$flow->id}", ['opening' => (object) []])
            ->assertOk()
            ->assertJsonPath('data.effective_template_id', $current->id);
    }

    public function test_with_the_window_closed_the_form_goes_out_with_its_opening_template(): void
    {
        $flow = $this->publishedFlow('flow-1');
        $template = $this->openingTemplate('tpl-1', 'Hola {{nombre}}, completá el alta');
        $flow->update(['opening' => ['default_template_id' => null, 'templates' => [$template->id => ['parameters' => [
            ['component' => 'body', 'name' => 'nombre', 'source' => 'field', 'path' => 'contact.name'],
        ]]]]]);
        Http::fake(['https://graph.facebook.com/*/123456789/messages' => Http::response(['messages' => [['id' => 'wamid.tpl-1']]])]);

        // Sin plantilla y sin ventana, el mensaje interactivo no sale.
        $this->postJson("/api/conversations/{$this->conversation->id}/send-flow", [
            'whatsapp_flow_id' => $flow->id, 'body' => 'Completá', 'cta' => 'Abrir',
        ])->assertUnprocessable();

        $this->postJson("/api/conversations/{$this->conversation->id}/send-flow", [
            'whatsapp_flow_id' => $flow->id,
            'template_id' => $template->id,
        ])->assertCreated();

        $session = WhatsAppFlowSession::sole();
        $this->assertSame(Message::where('external_id', 'wamid.tpl-1')->value('id'), $session->message_id);
        Http::assertSent(fn (Request $request) => ($request['template']['name'] ?? null) === 'alta'
            && collect($request['template']['components'])->contains(fn ($component) => $component['type'] === 'body'
                && $component['parameters'][0] === ['type' => 'text', 'text' => 'Jane Doe', 'parameter_name' => 'nombre'])
            && collect($request['template']['components'])->contains(fn ($component) => ($component['sub_type'] ?? null) === 'flow'
                && $component['parameters'][0]['action']['flow_token'] === $session->flow_token));
    }

    public function test_automation_uses_the_default_template_outside_the_window_and_skips_without_one(): void
    {
        $flow = $this->publishedFlow('flow-1');
        $handler = app(WhatsAppFlowActionHandler::class);
        $action = (new AutomationAction)->forceFill(['config' => [
            'channel_id' => $this->channel->id, 'whatsapp_flow_id' => $flow->id, 'body' => 'Completá', 'cta' => 'Abrir',
        ]]);
        $run = (new AutomationRun)->forceFill([
            'tenant_id' => $this->conversation->tenant_id,
            'subject_type' => 'conversation',
            'subject_id' => $this->conversation->id,
            'context' => [],
        ]);

        try {
            $handler->execute($action, $run);
            $this->fail('Sin plantilla y con la ventana cerrada la acción se tiene que saltear.');
        } catch (ActionSkippedException $exception) {
            $this->assertSame('messaging_window_closed', $exception->getMessage());
        }

        $this->openingTemplate('tpl-1', 'Completá el alta');
        Http::fake(['https://graph.facebook.com/*/123456789/messages' => Http::response(['messages' => [['id' => 'wamid.tpl-2']]])]);

        $result = $handler->execute($action, $run);

        $this->assertSame(Message::where('external_id', 'wamid.tpl-2')->value('id'), $result['message_id']);
        Http::assertSent(fn (Request $request) => ($request['type'] ?? null) === 'template' && $request['template']['name'] === 'alta');
    }

    public function test_sending_with_an_opening_template_that_is_not_ready_or_not_linked_is_rejected(): void
    {
        $flow = $this->publishedFlow('flow-1');
        $pending = $this->openingTemplate('tpl-pendiente', 'Completá el alta', name: 'pendiente', status: TemplateStatus::Pending);
        $unlinked = $this->openingTemplate('tpl-otro', 'Completá el alta', name: 'otro', flowId: 'flow-otro');
        $incomplete = $this->openingTemplate('tpl-variables', 'Hola {{nombre}}', name: 'variables');

        $send = fn (WhatsAppTemplate $template) => $this->postJson("/api/conversations/{$this->conversation->id}/send-flow", [
            'whatsapp_flow_id' => $flow->id,
            'template_id' => $template->id,
        ])->assertUnprocessable();

        $send($pending)->assertJsonPath('message', 'La plantilla «pendiente» no está aprobada por Meta.');
        $send($unlinked)->assertJsonPath('message', 'Esa plantilla no abre este formulario.');
        $send($incomplete)->assertJsonPath('message', 'Completá las variables de la plantilla «variables» en la apertura del formulario.');
        $this->assertSame(0, WhatsAppFlowSession::count());
    }

    public function test_closing_message_is_checked_before_saving(): void
    {
        Http::fake();
        $flow = $this->publishedFlow('flow-1');
        $nombre = ['type' => 'short_text', 'key' => 'nombre', 'label' => 'Nombre', 'input_type' => 'text', 'required' => true];
        $comentario = ['type' => 'long_text', 'key' => 'comentario', 'label' => 'Comentario', 'required' => false];
        // El cliente responde la versión publicada; «comentario» sólo existe en el borrador.
        $flow->forceFill([
            'builder_definition' => $this->definition([$nombre, $comentario]),
            'published_version' => ['flow_json' => $flow->flow_json, 'builder_definition' => $this->definition([$nombre]), 'field_mappings' => [], 'data_api_version' => null],
        ])->save();

        $otherConfig = WhatsAppConfig::create(['phone_number_id' => '999', 'waba_id' => 'waba-otra', 'bussines_token' => Crypt::encryptString('t')]);
        $foreign = $this->closingTemplate('ajena', $otherConfig->id);
        $closing = $this->closingTemplate('gracias', $this->channel->whatsapp_config_id);
        $opensAForm = $this->openingTemplate('tpl-1', 'Completá el alta');
        $save = fn (?array $message) => $this->putJson("/api/whatsapp-flows/{$flow->id}", ['completion_message' => $message]);
        $template = fn (WhatsAppTemplate $template, array $parameters) => ['type' => 'template', 'template_id' => $template->id, 'parameters' => $parameters];
        $cliente = ['component' => 'body', 'name' => 'cliente', 'source' => 'field', 'path' => 'answers.nombre'];

        $save($template($foreign, [$cliente]))->assertUnprocessable()->assertJsonValidationErrors(['completion_message.template_id']);
        $save($template($opensAForm, []))->assertUnprocessable()->assertJsonValidationErrors(['completion_message.template_id']);
        $save($template($closing, []))->assertUnprocessable()->assertJsonValidationErrors(['completion_message.parameters']);
        $save($template($closing, [['component' => 'body', 'name' => 'cliente', 'source' => 'field', 'path' => 'answers.comentario']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['completion_message.parameters.0.path']);
        $save(['type' => 'text', 'body' => 'Gracias', 'button_text' => 'Ver'])->assertUnprocessable()->assertJsonValidationErrors(['completion_message.url']);
        $save(['type' => 'text', 'body' => 'Gracias', 'button_text' => 'Ver', 'url' => 'http://petshop.example'])
            ->assertUnprocessable()->assertJsonValidationErrors(['completion_message.url']);
        $asset = fn (string $mime) => MediaAsset::create(['tenant_id' => $flow->tenant_id, 'name' => 'archivo', 'path' => 'media/archivo', 'mime_type' => $mime, 'size' => 1200]);
        $withImage = fn (array $image) => ['type' => 'text', 'body' => 'Gracias', 'image' => $image];
        $save($withImage(['source' => 'media_asset', 'media_asset_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors(['completion_message.image.media_asset_id']);
        $save($withImage(['source' => 'media_asset', 'media_asset_id' => $asset('application/pdf')->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['completion_message.image.media_asset_id']);
        $save($withImage(['source' => 'literal', 'value' => 'http://cdn.example/gracias.png']))->assertUnprocessable()->assertJsonValidationErrors(['completion_message.image.value']);
        $save($withImage(['source' => 'literal', 'value' => 'https://127.0.0.1/gracias.png']))->assertUnprocessable()->assertJsonValidationErrors(['completion_message.image.value']);
        $this->assertNull($flow->fresh()->completion_message);

        $save($template($closing, [$cliente]))->assertOk()
            ->assertJsonPath('data.completion_message', ['type' => 'template', 'template_id' => $closing->id, 'parameters' => [$cliente]])
            ->assertJsonPath('data.response_fields', [['key' => 'nombre', 'label' => 'Nombre']]);

        $photo = $asset('image/jpeg');
        $save($withImage(['source' => 'media_asset', 'media_asset_id' => $photo->id]))->assertOk()
            ->assertJsonPath('data.completion_message.image', ['source' => 'media_asset', 'media_asset_id' => $photo->id]);

        $save(null)->assertOk()->assertJsonPath('data.completion_message', null);
        Http::assertNothingSent();
    }

    private function closingTemplate(string $name, int $whatsappConfigId): WhatsAppTemplate
    {
        return WhatsAppTemplate::create([
            'tenant_id' => $this->channel->tenant_id,
            'whatsapp_config_id' => $whatsappConfigId,
            'external_id' => "tpl-{$name}",
            'name' => $name,
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [['type' => 'BODY', 'text' => 'Gracias {{cliente}}']],
        ]);
    }

    private function openingTemplate(
        string $externalId,
        string $body,
        string $name = 'alta',
        string $flowId = 'flow-1',
        TemplateStatus $status = TemplateStatus::Approved,
    ): WhatsAppTemplate {
        return WhatsAppTemplate::create([
            'tenant_id' => $this->channel->tenant_id,
            'whatsapp_config_id' => $this->channel->whatsapp_config_id,
            'external_id' => $externalId,
            'name' => $name,
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => $status,
            'components' => [
                ['type' => 'BODY', 'text' => $body],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'FLOW', 'text' => 'Abrir', 'flow_id' => $flowId, 'flow_action' => 'NAVIGATE']]],
            ],
        ]);
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

    private function flowMessage(string $externalId): Message
    {
        return Message::create([
            'tenant_id' => $this->conversation->tenant_id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $this->user->id,
            'content' => 'Formulario',
            'message_type' => MessageType::Flow,
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => $externalId,
            'interactive' => ['kind' => 'flow', 'flow_name' => 'Alta'],
        ]);
    }
}
