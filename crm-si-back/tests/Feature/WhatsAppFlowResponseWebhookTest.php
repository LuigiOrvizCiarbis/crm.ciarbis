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

    public function test_flow_reply_is_stored_completes_the_session_maps_fields_and_triggers_automations(): void
    {
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
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Ana',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $flow = WhatsAppFlow::create([
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
        $session = WhatsAppFlowSession::create([
            'tenant_id' => $tenant->id,
            'whatsapp_flow_id' => $flow->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'flow_token' => 'flow-token-1',
            'status' => WhatsAppFlowSession::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);

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
                                'response_json' => json_encode([
                                    'flow_token' => 'flow-token-1',
                                    'correo' => 'ana@x.com',
                                    'dni' => '30111222',
                                ]),
                            ],
                        ],
                    ]],
                ],
            ]],
        ]]])->assertOk();

        $message = Message::where('external_id', 'wamid.reply-1')->sole();
        $this->assertSame('flow_response', $message->message_type->value);
        $this->assertSame('30111222', $message->interactive['response']['dni']);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $session->fresh()->status);

        $contact->refresh();
        $this->assertSame('ana@x.com', $contact->email);
        $this->assertSame('30111222', $contact->custom_data['dni']);

        Bus::assertDispatched(
            EvaluateAutomationEventJob::class,
            fn (EvaluateAutomationEventJob $job) => $job->event['type'] === 'whatsapp_flow.response_received'
                && $job->event['whatsapp_flow_id'] === $flow->id,
        );
    }
}
