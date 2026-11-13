<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ContactFieldType;
use App\Enums\UserRole;
use App\Enums\WhatsAppFlowStatus;
use App\Jobs\EvaluateAutomationEventJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
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

    private function reply(string $token, array $answers): void
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
                        'id' => 'wamid.reply-1',
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
