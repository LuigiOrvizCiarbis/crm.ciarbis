<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppGroupParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppGroupWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_create_lifecycle_webhook_activates_pending_group(): void
    {
        $channel = $this->makeChannel();
        $group = WhatsAppGroup::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'request_id' => 'req_1',
            'subject' => 'Venta Juan',
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/whatsapp-webhook', $this->lifecyclePayload([
            'timestamp' => (string) now()->timestamp,
            'type' => 'group_create',
            'request_id' => 'req_1',
            'group_id' => '123@g.us',
            'subject' => 'Venta Juan',
            'invite_link' => 'https://chat.whatsapp.com/ABC123',
            'join_approval_mode' => 'auto_approve',
        ]));

        $response->assertOk();

        $group->refresh();
        $this->assertSame('active', $group->status->value);
        $this->assertSame('123@g.us', $group->group_id);
        $this->assertSame('https://chat.whatsapp.com/ABC123', $group->invite_link);
        $this->assertNotNull($group->conversation_id);

        $conversation = Conversation::find($group->conversation_id);
        $this->assertSame('group', $conversation->kind);
        $this->assertNull($conversation->contact_id);
        $this->assertFalse((bool) $conversation->ai_autoreply_enabled);
    }

    public function test_group_create_webhook_marks_failed_on_meta_errors(): void
    {
        $channel = $this->makeChannel();
        $group = WhatsAppGroup::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'request_id' => 'req_2',
            'subject' => 'Venta rechazada',
            'status' => 'pending',
        ]);

        $this->postJson('/api/whatsapp-webhook', $this->lifecyclePayload([
            'timestamp' => (string) now()->timestamp,
            'type' => 'group_create',
            'request_id' => 'req_2',
            'group_id' => '456@g.us',
            'errors' => [['code' => 100, 'message' => 'No autorizado para crear grupos', 'title' => 'Error']],
        ]))->assertOk();

        $group->refresh();
        $this->assertSame('failed', $group->status->value);
        $this->assertNotNull($group->error_message);
        $this->assertNull($group->conversation_id);
    }

    public function test_group_create_webhook_without_matching_request_id_dispatches_reconcile_job(): void
    {
        Queue::fake();
        $channel = $this->makeChannel();

        $this->postJson('/api/whatsapp-webhook', $this->lifecyclePayload([
            'timestamp' => (string) now()->timestamp,
            'type' => 'group_create',
            'request_id' => 'req_never_persisted',
            'group_id' => '789@g.us',
        ]))->assertOk();

        Queue::assertPushed(\App\Jobs\ReconcileWhatsAppGroupJob::class, fn ($job) => $job->requestId === 'req_never_persisted'
            && $job->channelId === $channel->id);
    }

    public function test_group_participants_add_creates_participant(): void
    {
        $channel = $this->makeChannel();
        $group = $this->makeActiveGroup($channel);

        $this->postJson('/api/whatsapp-webhook', $this->participantsPayload([
            'timestamp' => (string) now()->timestamp,
            'group_id' => $group->group_id,
            'type' => 'group_participants_add',
            'reason' => 'invite_link',
            'added_participants' => [
                ['wa_id' => '5491122334455', 'user_id' => 'BSUID1', 'username' => 'juan'],
            ],
        ]))->assertOk();

        $this->assertDatabaseHas('whatsapp_group_participants', [
            'whatsapp_group_id' => $group->id,
            'wa_id' => '5491122334455',
            'status' => 'active',
        ]);

        $group->refresh();
        $this->assertSame(1, $group->total_participant_count);
    }

    public function test_group_participants_remove_marks_removed(): void
    {
        $channel = $this->makeChannel();
        $group = $this->makeActiveGroup($channel);
        WhatsAppGroupParticipant::create([
            'whatsapp_group_id' => $group->id,
            'wa_id' => '5491122334455',
            'status' => 'active',
        ]);

        $this->postJson('/api/whatsapp-webhook', $this->participantsPayload([
            'timestamp' => (string) now()->timestamp,
            'group_id' => $group->group_id,
            'type' => 'group_participants_remove',
            'removed_participants' => [['wa_id' => '5491122334455']],
            'initiated_by' => 'business',
        ]))->assertOk();

        $this->assertDatabaseHas('whatsapp_group_participants', [
            'whatsapp_group_id' => $group->id,
            'wa_id' => '5491122334455',
            'status' => 'removed',
            'removed_by' => 'business',
        ]);

        $group->refresh();
        $this->assertSame(0, $group->total_participant_count);
    }

    public function test_group_join_request_created_and_revoked(): void
    {
        $channel = $this->makeChannel();
        $group = $this->makeActiveGroup($channel);

        $this->postJson('/api/whatsapp-webhook', $this->participantsPayload([
            'timestamp' => (string) now()->timestamp,
            'group_id' => $group->group_id,
            'type' => 'group_join_request_created',
            'join_request_id' => 'jr_1',
            'wa_id' => '5491122334455',
        ]))->assertOk();

        $this->assertDatabaseHas('whatsapp_group_participants', [
            'whatsapp_group_id' => $group->id,
            'wa_id' => '5491122334455',
            'status' => 'pending_approval',
            'join_request_id' => 'jr_1',
        ]);

        $this->postJson('/api/whatsapp-webhook', $this->participantsPayload([
            'timestamp' => (string) now()->timestamp,
            'group_id' => $group->group_id,
            'type' => 'group_join_request_revoked',
            'join_request_id' => 'jr_1',
            'wa_id' => '5491122334455',
        ]))->assertOk();

        $this->assertDatabaseHas('whatsapp_group_participants', [
            'whatsapp_group_id' => $group->id,
            'join_request_id' => 'jr_1',
            'status' => 'rejected',
        ]);
    }

    public function test_group_settings_update_syncs_subject(): void
    {
        $channel = $this->makeChannel();
        $group = $this->makeActiveGroup($channel);

        $response = $this->postJson('/api/whatsapp-webhook', [
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'group_settings_update',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => $channel->whatsappConfig->phone_number_id],
                        'groups' => [[
                            'group_id' => $group->group_id,
                            'subject' => 'Nuevo nombre',
                            'description' => 'Nueva descripción',
                        ]],
                    ],
                ]],
            ]],
        ]);

        $response->assertOk();
        $group->refresh();
        $this->assertSame('Nuevo nombre', $group->subject);
        $this->assertSame('Nueva descripción', $group->description);
    }

    public function test_group_status_update_suspends_group(): void
    {
        $channel = $this->makeChannel();
        $group = $this->makeActiveGroup($channel);

        $response = $this->postJson('/api/whatsapp-webhook', [
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'group_status_update',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => $channel->whatsappConfig->phone_number_id],
                        'groups' => [['group_id' => $group->group_id, 'suspended' => true]],
                    ],
                ]],
            ]],
        ]);

        $response->assertOk();
        $group->refresh();
        $this->assertTrue((bool) $group->suspended);
        $this->assertSame('suspended', $group->status->value);
    }

    public function test_webhook_returns_200_on_unknown_group(): void
    {
        $channel = $this->makeChannel();

        $response = $this->postJson('/api/whatsapp-webhook', $this->participantsPayload([
            'timestamp' => (string) now()->timestamp,
            'group_id' => 'unknown-group-id@g.us',
            'type' => 'group_participants_add',
            'added_participants' => [['wa_id' => '5491100000000']],
        ]));

        $response->assertOk();
        $this->assertDatabaseCount('whatsapp_group_participants', 0);
    }

    private function lifecyclePayload(array $groupEvent): array
    {
        $channel = Channel::withoutGlobalScopes()->where('type', ChannelType::WHATSAPP)->latest()->first();

        return [
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'group_lifecycle_update',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '15550783881',
                            'phone_number_id' => $channel->whatsappConfig->phone_number_id,
                        ],
                        'groups' => [$groupEvent],
                    ],
                ]],
            ]],
        ];
    }

    private function participantsPayload(array $groupEvent): array
    {
        $channel = Channel::withoutGlobalScopes()->where('type', ChannelType::WHATSAPP)->latest()->first();

        return [
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'group_participants_update',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '15550783881',
                            'phone_number_id' => $channel->whatsappConfig->phone_number_id,
                        ],
                        'groups' => [$groupEvent],
                    ],
                ]],
            ]],
        ];
    }

    private function makeChannel(): Channel
    {
        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 11 0000-0000',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);

        return Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);
    }

    private function makeActiveGroup(Channel $channel): WhatsAppGroup
    {
        return WhatsAppGroup::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'group_id' => 'active-group-'.uniqid().'@g.us',
            'subject' => 'Grupo activo',
            'status' => 'active',
        ]);
    }
}
