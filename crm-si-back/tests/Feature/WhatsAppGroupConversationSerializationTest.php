<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El plan de grupos advertía explícitamente sobre este gap: Message::sender()
 * es un morphTo sin morph map, así que ->load('sender') no funciona. Sin
 * adjuntar `sender` a mano en el controller, el front no puede mostrar quién
 * escribió dentro de un grupo (ver ConversationHeader/MessageBubble).
 */
class WhatsAppGroupConversationSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_attaches_sender_name_and_group_summary_for_group_conversations(): void
    {
        [$user, $channel, $group, $conversation] = $this->context();

        $contact = Contact::create([
            'tenant_id' => $channel->tenant_id,
            'name' => 'Juan Pérez',
            'phone' => '5491122334455',
            'source' => 'whatsapp',
        ]);

        Message::create([
            'tenant_id' => $channel->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => '¿Qué opinan?',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/conversations/{$conversation->id}");

        $response->assertOk();
        $response->assertJsonPath('data.group.subject', 'Grupo activo');
        $response->assertJsonPath('data.messages.0.sender.name', 'Juan Pérez');
        $response->assertJsonPath('data.messages.0.sender.id', $contact->id);
    }

    public function test_fetch_messages_attaches_sender_name_for_group_conversations(): void
    {
        [$user, $channel, $group, $conversation] = $this->context();

        $contact = Contact::create([
            'tenant_id' => $channel->tenant_id,
            'name' => 'Ana',
            'phone' => '5491100000001',
            'source' => 'whatsapp',
        ]);

        Message::create([
            'tenant_id' => $channel->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/conversations/{$conversation->id}/messages");

        $response->assertOk();
        $response->assertJsonPath('data.0.sender.name', 'Ana');
    }

    public function test_show_does_not_attach_sender_for_direct_conversations(): void
    {
        [$user, $channel] = $this->context();

        $contact = Contact::create([
            'tenant_id' => $channel->tenant_id,
            'name' => 'Cliente directo',
            'phone' => '5491100000002',
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'kind' => 'direct',
            'status' => 'open',
        ]);
        Message::create([
            'tenant_id' => $channel->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/conversations/{$conversation->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.messages.0.sender');
    }

    /** @return array{0: User, 1: Channel, 2: WhatsAppGroup, 3: Conversation} */
    private function context(): array
    {
        $tenant = $this->createTenantWithRoles('Acme '.uniqid());
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');

        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 11 0000-0000',
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

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => null,
            'kind' => 'group',
            'status' => 'open',
        ]);

        $group = WhatsAppGroup::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'conversation_id' => $conversation->id,
            'group_id' => 'group-'.uniqid().'@g.us',
            'subject' => 'Grupo activo',
            'status' => 'active',
        ]);

        return [$user, $channel, $group, $conversation];
    }
}
