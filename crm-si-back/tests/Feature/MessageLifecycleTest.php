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
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Services\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_latest_message_refreshes_conversation_preview(): void
    {
        [$user, $conversation, $firstMessage, $latestMessage] = $this->createConversationWithMessages();

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/messages/{$latestMessage->id}", [
            'content' => 'Latest message edited',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.content', 'Latest message edited');

        $conversation->refresh();

        $this->assertSame('Latest message edited', $conversation->last_message_content);
        $this->assertTrue($conversation->last_message_at->equalTo($latestMessage->created_at));
        $this->assertSame('First message', $firstMessage->fresh()->content);
    }

    public function test_deleting_latest_message_recomputes_preview_and_keeps_tombstone_in_history(): void
    {
        [$user, $conversation, $firstMessage, $latestMessage] = $this->createConversationWithMessages();

        Sanctum::actingAs($user);

        $this->deleteJson("/api/messages/{$latestMessage->id}")
            ->assertOk();

        $conversation->refresh();

        $this->assertSame('First message', $conversation->last_message_content);
        $this->assertTrue($conversation->last_message_at->equalTo($firstMessage->created_at));

        $deletedMessage = Message::withTrashed()->find($latestMessage->id);
        $this->assertNotNull($deletedMessage?->deleted_at);

        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.1.id', $latestMessage->id)
            ->assertJsonPath('data.messages.1.deleted_at', $deletedMessage->deleted_at?->toJSON());

        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_audio_message_updates_conversation_preview_with_audio_label(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Main channel',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '+5491111111111',
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $user->id,
            'content' => '',
            'message_type' => MessageType::Audio,
            'direction' => MessageDirection::OUTBOUND,
        ]);

        $conversation->refresh();

        $this->assertSame('🎵 Audio', $conversation->last_message_content);
        $this->assertNotNull($conversation->last_message_at);
    }

    public function test_sticker_message_updates_conversation_preview_with_sticker_label(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Main channel',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '+5491111111111',
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => '',
            'message_type' => MessageType::Sticker,
            'media_url' => '/storage/messages/test-sticker.webp',
            'media_mime_type' => 'image/webp',
            'media_filename' => 'test-sticker.webp',
            'direction' => MessageDirection::INBOUND,
        ]);

        $conversation->refresh();

        $this->assertSame('🏷️ Sticker', $conversation->last_message_content);
        $this->assertNotNull($conversation->last_message_at);
    }

    public function test_whatsapp_revoke_event_soft_deletes_original_message(): void
    {
        [$tenant, $channel] = $this->createWhatsAppChannelContext();

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $message = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'wamid.original.revoke',
        ]);

        $conversation->syncLastMessageSummary();

        app(WhatsAppMessageService::class)->processIncomingMessage([
            'value' => [
                'metadata' => [
                    'phone_number_id' => '123456789',
                ],
                'messages' => [[
                    'from' => '5492235112208',
                    'id' => 'wamid.event.revoke',
                    'timestamp' => '1775921049',
                    'type' => 'revoke',
                    'revoke' => [
                        'original_message_id' => 'wamid.original.revoke',
                    ],
                ]],
            ],
        ]);

        $message->refresh();
        $conversation->refresh();

        $this->assertSoftDeleted('messages', ['id' => $message->id]);
        $this->assertNull($conversation->last_message_content);
        $this->assertNull($conversation->last_message_at);
    }

    public function test_whatsapp_edit_event_updates_original_message_content(): void
    {
        [$tenant, $channel] = $this->createWhatsAppChannelContext();

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $message = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'wamid.original.edit',
        ]);

        $conversation->syncLastMessageSummary();

        $editedMessage = app(WhatsAppMessageService::class)->processIncomingMessage([
            'value' => [
                'metadata' => [
                    'phone_number_id' => '123456789',
                ],
                'messages' => [[
                    'from' => '5492235112208',
                    'id' => 'wamid.event.edit',
                    'timestamp' => '1775921075',
                    'type' => 'edit',
                    'edit' => [
                        'original_message_id' => 'wamid.original.edit',
                        'message' => [
                            'type' => 'text',
                            'text' => [
                                'body' => 'Hol',
                            ],
                        ],
                    ],
                ]],
            ],
        ]);

        $message->refresh();
        $conversation->refresh();

        $this->assertNotNull($editedMessage);
        $this->assertSame($message->id, $editedMessage->id);
        $this->assertSame('Hol', $message->content);
        $this->assertSame('Hola', $message->original_content);
        $this->assertNotNull($message->edited_at);
        $this->assertSame('Hol', $conversation->last_message_content);
    }

    /**
     * @return array{0: User, 1: Conversation, 2: Message, 3: Message}
     */
    private function createConversationWithMessages(): array
    {
        $tenant = $this->createTenantWithRoles();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        // Las policies de Message autorizan por permisos de Spatie: sin rol
        // asignado, editar o borrar un mensaje responde 403.
        $user->assignRole('Owner');

        Sanctum::actingAs($user);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Main channel',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '+5491111111111',
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $firstMessage = $this->createMessage($conversation, $user, 'First message', now()->subMinute());
        $latestMessage = $this->createMessage($conversation, $user, 'Latest message', now());

        $conversation->syncLastMessageSummary();
        $conversation->refresh();

        return [$user, $conversation, $firstMessage, $latestMessage];
    }

    private function createMessage(Conversation $conversation, User $user, string $content, $createdAt): Message
    {
        $message = Message::unguarded(function () use ($conversation, $user, $content, $createdAt) {
            return Message::create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'sender_type' => SenderType::USER,
                'sender_id' => $user->id,
                'content' => $content,
                'message_type' => MessageType::Text,
                'direction' => MessageDirection::OUTBOUND,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        });

        $message->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $message->fresh();
    }

    /**
     * @return array{0: Tenant, 1: Channel}
     */
    private function createWhatsAppChannelContext(): array
    {
        $tenant = Tenant::create([
            'name' => 'Acme',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Main channel',
            'status' => 'active',
        ]);

        $config = WhatsAppConfig::create([
            'phone_number_id' => '123456789',
            'display_phone_number' => '+54 9 223 511-2208',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);

        $channel->update([
            'whatsapp_config_id' => $config->id,
        ]);

        return [$tenant, $channel->fresh()];
    }
}
