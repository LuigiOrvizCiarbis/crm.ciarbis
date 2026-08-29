<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Models\AiConfig;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppGroup;
use App\Events\MessageSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppGroupIncomingMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_incoming_group_message_attaches_to_group_conversation_without_creating_a_new_one(): void
    {
        [$channel, $group] = $this->makeActiveGroupWithConversation();

        $this->postJson('/api/whatsapp-webhook', $this->groupMessagePayload($channel, $group->group_id, [
            'from' => '5491122334455',
            'name' => 'Juan Pérez',
        ]));

        $this->assertSame(1, Conversation::withoutGlobalScopes()->count());

        $message = Message::withoutGlobalScopes()->where('conversation_id', $group->conversation_id)->first();
        $this->assertNotNull($message);
        $this->assertSame('contact', $message->sender_type->value);

        $contact = Contact::withoutGlobalScopes()->where('phone', '5491122334455')->first();
        $this->assertNotNull($contact);
        $this->assertSame($contact->id, $message->sender_id);
    }

    public function test_incoming_group_message_broadcast_carries_sender_name_in_realtime(): void
    {
        Event::fake([MessageSent::class]);
        [$channel, $group] = $this->makeActiveGroupWithConversation();

        $this->postJson('/api/whatsapp-webhook', $this->groupMessagePayload($channel, $group->group_id, [
            'from' => '5491122334455',
            'name' => 'Juan Pérez',
        ]));

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) {
            return $event->message->sender['name'] === 'Juan Pérez';
        });
    }

    public function test_incoming_group_message_creates_contact_for_unknown_participant(): void
    {
        [$channel, $group] = $this->makeActiveGroupWithConversation();

        $this->assertDatabaseMissing('contacts', ['phone' => '5491199887766']);

        $this->postJson('/api/whatsapp-webhook', $this->groupMessagePayload($channel, $group->group_id, [
            'from' => '5491199887766',
            'name' => 'Desconocido',
        ]));

        $this->assertDatabaseHas('contacts', ['phone' => '5491199887766', 'source' => 'whatsapp']);
    }

    public function test_incoming_group_message_does_not_dispatch_ai_reply(): void
    {
        Queue::fake();
        [$channel, $group] = $this->makeActiveGroupWithConversation();

        // Simula que alguien reactivó el flag a mano: el guard duro de grupo
        // tiene que cortar igual, no solo el default en false al crear.
        Conversation::withoutGlobalScopes()->find($group->conversation_id)
            ->update(['ai_autoreply_enabled' => true]);

        AiConfig::withoutGlobalScopes()->create([
            'tenant_id' => $channel->tenant_id,
            'provider' => 'claude',
            'enabled' => true,
            'api_key' => Crypt::encryptString('fake-key'),
        ]);

        $this->postJson('/api/whatsapp-webhook', $this->groupMessagePayload($channel, $group->group_id, [
            'from' => '5491122334455',
            'name' => 'Juan Pérez',
        ]));

        Queue::assertNotPushed(\App\Jobs\GenerateAiReplyJob::class);
    }

    public function test_direct_message_still_creates_one_to_one_conversation(): void
    {
        $channel = $this->makeChannel();

        $this->postJson('/api/whatsapp-webhook', [
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => $channel->whatsappConfig->phone_number_id],
                        'contacts' => [['profile' => ['name' => 'Ana']]],
                        'messages' => [[
                            'from' => '5491100000000',
                            'id' => 'wamid.direct1',
                            'timestamp' => (string) now()->timestamp,
                            'text' => ['body' => 'Hola'],
                            'type' => 'text',
                        ]],
                    ],
                ]],
            ]],
        ]);

        $conversation = Conversation::withoutGlobalScopes()->first();
        $this->assertNotNull($conversation);
        $this->assertSame('direct', $conversation->kind);
        $this->assertNotNull($conversation->contact_id);
    }

    private function groupMessagePayload(Channel $channel, string $groupId, array $sender): array
    {
        return [
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => $channel->whatsappConfig->phone_number_id],
                        'contacts' => [['profile' => ['name' => $sender['name']]]],
                        'messages' => [[
                            'from' => $sender['from'],
                            'group_id' => $groupId,
                            'id' => 'wamid.group.'.uniqid(),
                            'timestamp' => (string) now()->timestamp,
                            'text' => ['body' => '¿Qué opinan?'],
                            'type' => 'text',
                        ]],
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

    /** @return array{0: Channel, 1: WhatsAppGroup} */
    private function makeActiveGroupWithConversation(): array
    {
        $channel = $this->makeChannel();

        $conversation = Conversation::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => null,
            'kind' => 'group',
            'status' => 'open',
            'ai_autoreply_enabled' => false,
        ]);

        $group = WhatsAppGroup::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'conversation_id' => $conversation->id,
            'group_id' => 'group-'.uniqid().'@g.us',
            'subject' => 'Grupo activo',
            'status' => 'active',
        ]);

        return [$channel, $group];
    }
}
