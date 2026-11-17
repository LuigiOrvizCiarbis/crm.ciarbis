<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ContactFieldType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Enums\WhatsAppFlowStatus;
use App\Jobs\EvaluateAutomationEventJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\Conversation;
use App\Models\MediaAsset;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppFlowResponseWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

    private Conversation $conversation;

    private WhatsAppFlow $flow;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([EvaluateAutomationEventJob::class]);
        Http::fake();

        $tenant = Tenant::create(['name' => 'Acme']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $config = WhatsAppConfig::create([
            'phone_number_id' => '123456789',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);
        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'dni',
            'label' => 'DNI',
            'type' => ContactFieldType::Text,
            'display_order' => 1,
        ]);
        $this->contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Ana',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);
        $this->conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $this->contact->id,
            'status' => 'open',
        ]);
        $this->flow = WhatsAppFlow::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $config->id,
            'meta_flow_id' => 'flow-1',
            'name' => 'Alta',
            'status' => WhatsAppFlowStatus::Published,
            'categories' => ['SIGN_UP'],
            'field_mappings' => [
                ['response_key' => 'correo', 'target' => 'contact.email'],
                ['response_key' => 'dni', 'target' => 'custom_data.dni'],
            ],
        ]);
    }

    public function test_flow_reply_is_stored_completes_the_session_maps_fields_and_triggers_automations(): void
    {
        $session = $this->flowSession('flow-token-1', WhatsAppFlowSession::STATUS_PENDING);

        $this->reply('flow-token-1', ['correo' => 'ana@x.com', 'dni' => '30111222']);

        $message = Message::where('external_id', 'wamid.reply-1')->sole();
        $this->assertSame('flow_response', $message->message_type->value);
        $this->assertSame('30111222', $message->interactive['response']['dni']);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $session->fresh()->status);

        $this->contact->refresh();
        $this->assertSame('ana@x.com', $this->contact->email);
        $this->assertSame('30111222', $this->contact->custom_data['dni']);

        Bus::assertDispatched(
            EvaluateAutomationEventJob::class,
            fn (EvaluateAutomationEventJob $job) => $job->event['type'] === 'whatsapp_flow.response_received'
                && $job->event['whatsapp_flow_id'] === $this->flow->id,
        );
    }

    public function test_answering_a_replaced_send_records_it_and_closes_the_newer_send(): void
    {
        $replaced = $this->flowSession('token-viejo', WhatsAppFlowSession::STATUS_SUPERSEDED);
        $newer = $this->flowSession('token-nuevo', WhatsAppFlowSession::STATUS_PENDING);

        $this->reply('token-viejo', ['correo' => 'ana@x.com']);

        $replaced->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $replaced->status);
        $this->assertSame(['correo' => 'ana@x.com'], $replaced->response);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $newer->fresh()->status);
    }

    public function test_first_reply_sends_the_completion_message_with_its_link_button(): void
    {
        $this->flow->update(['completion_message' => [
            'type' => 'text',
            'body' => 'Listo, registramos el DNI {{dni}}.{{sin_respuesta}}',
            'button_text' => 'Ver catálogo',
            'url' => 'https://petshop.example/catalogo',
        ]]);
        $this->flowSession('flow-token-1', WhatsAppFlowSession::STATUS_PENDING);

        $this->reply('flow-token-1', ['dni' => '30111222']);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages')
            && $request['type'] === 'interactive'
            && $request['interactive']['type'] === 'cta_url'
            && $request['interactive']['body']['text'] === 'Listo, registramos el DNI 30111222.'
            && $request['interactive']['action']['parameters'] === ['display_text' => 'Ver catálogo', 'url' => 'https://petshop.example/catalogo']);

        $sent = Message::where('conversation_id', $this->conversation->id)->where('direction', 'outbound')->sole();
        $this->assertSame('flow_completion', $sent->interactive['kind']);
        $this->assertStringContainsString('https://petshop.example/catalogo', $sent->content);
    }

    public function test_a_text_closing_carries_its_image_and_still_goes_out_when_the_image_is_gone(): void
    {
        $asset = MediaAsset::create(['tenant_id' => $this->flow->tenant_id, 'name' => 'gracias.jpg', 'path' => 'media/gracias.jpg', 'mime_type' => 'image/jpeg', 'size' => 1200]);
        $image = ['source' => 'media_asset', 'media_asset_id' => $asset->id];
        $closing = fn (array $extra) => $this->flow->update(['completion_message' => ['type' => 'text', 'body' => 'Gracias, DNI {{dni}}', 'image' => $image, ...$extra]]);
        $messages = fn () => Http::recorded(fn ($request) => str_ends_with($request->url(), '/messages'));

        // Con botón, la imagen es el encabezado del mensaje interactivo.
        $closing(['button_text' => 'Ver catálogo', 'url' => 'https://petshop.example/catalogo']);
        $this->flowSession('flow-token-1', WhatsAppFlowSession::STATUS_PENDING);
        $this->reply('flow-token-1', ['dni' => '30111222']);
        $this->assertSame(['type' => 'image', 'image' => ['link' => $asset->publicUrl()]], $messages()->last()[0]['interactive']['header']);

        // Sin botón, va como mensaje de imagen con el texto de epígrafe.
        $closing([]);
        $this->flowSession('flow-token-2', WhatsAppFlowSession::STATUS_PENDING);
        $this->reply('flow-token-2', ['dni' => '30111222'], 'wamid.reply-2');
        $request = $messages()->last()[0];
        $this->assertSame('image', $request['type']);
        $this->assertSame(['link' => $asset->publicUrl(), 'caption' => 'Gracias, DNI 30111222'], $request['image']);
        $sent = Message::where('conversation_id', $this->conversation->id)->where('direction', 'outbound')->latest('id')->first();
        $this->assertSame('image', $sent->message_type->value);
        $this->assertSame($asset->publicUrl(), $sent->media_url);

        // Un archivo borrado no frena el cierre: sale el texto solo.
        $asset->delete();
        $this->flowSession('flow-token-3', WhatsAppFlowSession::STATUS_PENDING);
        $this->reply('flow-token-3', ['dni' => '30111222'], 'wamid.reply-3');
        $request = $messages()->last()[0];
        $this->assertSame('text', $request['type']);
        $this->assertSame('Gracias, DNI 30111222', $request['text']['body']);
    }

    public function test_answering_an_already_completed_send_does_not_repeat_the_completion_message(): void
    {
        $this->flow->update(['completion_message' => ['type' => 'text', 'body' => 'Gracias']]);
        $this->flowSession('flow-token-1', WhatsAppFlowSession::STATUS_COMPLETED);

        $this->reply('flow-token-1', ['dni' => '30111222']);

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));
    }

    public function test_a_template_closing_fills_its_variables_with_the_answers(): void
    {
        $template = WhatsAppTemplate::create([
            'tenant_id' => $this->flow->tenant_id,
            'whatsapp_config_id' => $this->flow->whatsapp_config_id,
            'external_id' => 'tpl-gracias',
            'name' => 'gracias',
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [['type' => 'BODY', 'text' => 'Recibimos el DNI {{dni}}']],
        ]);
        $this->flow->update(['completion_message' => ['type' => 'template', 'template_id' => $template->id, 'parameters' => [
            ['component' => 'body', 'name' => 'dni', 'source' => 'field', 'path' => 'answers.dni'],
        ]]]);
        $this->flowSession('flow-token-1', WhatsAppFlowSession::STATUS_PENDING);

        $this->reply('flow-token-1', ['dni' => '30111222']);

        Http::assertSent(fn ($request) => ($request['type'] ?? null) === 'template'
            && $request['template']['name'] === 'gracias'
            && $request['template']['components'][0]['parameters'][0] === ['type' => 'text', 'text' => '30111222', 'parameter_name' => 'dni']);
    }

    public function test_a_template_closing_uses_the_contact_data_the_form_just_saved(): void
    {
        $template = WhatsAppTemplate::create([
            'tenant_id' => $this->flow->tenant_id,
            'whatsapp_config_id' => $this->flow->whatsapp_config_id,
            'external_id' => 'tpl-confirmacion',
            'name' => 'confirmacion',
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [['type' => 'BODY', 'text' => 'Te escribimos a {{correo}}']],
        ]);
        $this->flow->update(['completion_message' => ['type' => 'template', 'template_id' => $template->id, 'parameters' => [
            ['component' => 'body', 'name' => 'correo', 'source' => 'field', 'path' => 'contact.email'],
        ]]]);
        $this->flowSession('flow-token-1', WhatsAppFlowSession::STATUS_PENDING);

        $this->reply('flow-token-1', ['correo' => 'ana@x.com']);

        Http::assertSent(fn ($request) => ($request['type'] ?? null) === 'template'
            && $request['template']['name'] === 'confirmacion'
            && $request['template']['components'][0]['parameters'][0] === ['type' => 'text', 'text' => 'ana@x.com', 'parameter_name' => 'correo']);
    }

    private function flowSession(string $token, string $status): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::create([
            'tenant_id' => $this->conversation->tenant_id,
            'whatsapp_flow_id' => $this->flow->id,
            'conversation_id' => $this->conversation->id,
            'contact_id' => $this->contact->id,
            'flow_token' => $token,
            'status' => $status,
            'expires_at' => now()->addDay(),
        ]);
    }

    private function reply(string $token, array $answers, string $messageId = 'wamid.reply-1'): void
    {
        $this->postJson('/api/whatsapp-webhook', ['entry' => [[
            'id' => 'waba-test',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => '123456789'],
                    'contacts' => [['profile' => ['name' => 'Ana'], 'wa_id' => '5492235112208']],
                    'messages' => [[
                        'from' => '5492235112208',
                        'id' => $messageId,
                        'timestamp' => (string) now()->timestamp,
                        'type' => 'interactive',
                        'context' => ['id' => 'wamid.flow-1'],
                        'interactive' => [
                            'type' => 'nfm_reply',
                            'nfm_reply' => [
                                'name' => 'flow',
                                'body' => 'Sent',
                                'response_json' => json_encode(['flow_token' => $token, ...$answers]),
                            ],
                        ],
                    ]],
                ],
            ]],
        ]]])->assertOk();
    }
}
