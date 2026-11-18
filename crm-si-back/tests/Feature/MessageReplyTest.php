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
use App\Models\InstagramConfig;
use App\Models\Message;
use App\Models\MessengerConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Services\InstagramMessageService;
use App\Services\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReceivesInboundMessages;
use Tests\TestCase;

class MessageReplyTest extends TestCase
{
    use ReceivesInboundMessages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.facebook.graph_version', 'v26.0');
        config()->set('services.facebook.public_media_base_url', 'https://public.example.com');
    }

    public function test_whatsapp_text_reply_sends_context_and_persists_link(): void
    {
        Http::fake(['https://graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.reply']]], 200)]);
        [$user, $conversation] = $this->conversation(ChannelType::WHATSAPP);
        $target = $this->target($conversation, 'wamid.target');
        Sanctum::actingAs($user);

        $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'Hola',
            'reply_to_message_id' => $target->id,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.reply_to_message_id', $target->id)
            ->assertJsonPath('data.reply_to.id', $target->id);

        Http::assertSent(fn ($request) => ($request['context']['message_id'] ?? null) === 'wamid.target');
    }

    public function test_instagram_and_messenger_reply_send_top_level_reply_to_mid(): void
    {
        // external_id es único: cada envío necesita un mid distinto.
        Http::fake(['https://graph.facebook.com/*/messages' => fn () => Http::response(['message_id' => 'mid.reply.'.uniqid()], 200)]);

        foreach ([ChannelType::INSTAGRAM, ChannelType::FACEBOOK] as $channelType) {
            [$user, $conversation] = $this->conversation($channelType);
            $target = $this->target($conversation, 'mid.target.'.$channelType->value);
            Sanctum::actingAs($user);

            $response = $this->postJson('/api/messages', [
                'conversation_id' => $conversation->id,
                'type' => 'text',
                'content' => 'Hola',
                'reply_to_message_id' => $target->id,
            ])->assertStatus(201);

            Http::assertSent(fn ($request) => ($request['reply_to']['mid'] ?? null) === 'mid.target.'.$channelType->value);
            $this->assertSame($target->id, Message::find($response->json('data.id'))->reply_to_message_id);
        }
    }

    public function test_reply_target_must_belong_to_conversation_and_be_synced(): void
    {
        Http::fake();
        [$user, $conversation] = $this->conversation(ChannelType::WHATSAPP);
        [, $otherConversation] = $this->conversation(ChannelType::WHATSAPP);
        Sanctum::actingAs($user);

        $foreign = $this->target($otherConversation, 'wamid.foreign');
        $unsynced = $this->target($conversation, null);
        $deleted = $this->target($conversation, 'wamid.deleted');
        $deleted->delete();
        $valid = $this->target($conversation, 'wamid.valid');

        $cases = [
            [['type' => 'text', 'content' => 'x', 'reply_to_message_id' => $foreign->id], 'El mensaje al que querés responder ya no está disponible.'],
            [['type' => 'text', 'content' => 'x', 'reply_to_message_id' => $unsynced->id], 'Este mensaje todavía no se sincronizó con el canal y no se puede responder.'],
            [['type' => 'text', 'content' => 'x', 'reply_to_message_id' => $deleted->id], 'El mensaje al que querés responder ya no está disponible.'],
            [['type' => 'contacts', 'contact_ids' => [$conversation->contact_id], 'reply_to_message_id' => $valid->id], 'Responder a un mensaje no está disponible para este tipo de envío.'],
        ];

        foreach ($cases as [$payload, $expected]) {
            $this->postJson('/api/messages', ['conversation_id' => $conversation->id, ...$payload])
                ->assertStatus(422)
                ->assertJsonPath('message', $expected);
        }

        Http::assertNothingSent();
    }

    public function test_whatsapp_inbound_context_links_reply_including_self_quote(): void
    {
        Http::fake();
        [$tenant] = $this->createWhatsAppChannelContext();
        $service = app(WhatsAppMessageService::class);

        $service->processIncomingMessage($this->whatsAppInbound('wamid.A', 'Primero'));
        $service->processIncomingMessage($this->whatsAppInbound('wamid.B', 'Respuesta', [
            'from' => '5492235112208',
            'id' => 'wamid.A',
        ]));

        $messages = Message::where('tenant_id', $tenant->id)->orderBy('id')->get();
        $this->assertCount(2, $messages);
        [$original, $reply] = [$messages[0], $messages[1]];
        $this->assertSame($original->id, $reply->reply_to_message_id);
        $this->assertSame('Primero', $original->content);
        $this->assertNull($original->edited_at);
    }

    public function test_instagram_inbound_reply_to_mid_links_reply(): void
    {
        Http::fake();
        [, $conversation] = $this->conversation(ChannelType::INSTAGRAM);
        $igUserId = $conversation->channel->instagramConfig->ig_user_id;
        $service = app(InstagramMessageService::class);
        $event = fn (array $message) => [
            'sender' => ['id' => 'IGSID_X'],
            'recipient' => ['id' => $igUserId],
            'timestamp' => now()->getTimestampMs(),
            'message' => $message,
        ];

        $first = $service->processIncomingMessage($igUserId, $event(['mid' => 'm1', 'text' => 'hola']));
        $reply = $service->processIncomingMessage($igUserId, $event(['mid' => 'm2', 'text' => 'ok', 'reply_to' => ['mid' => 'm1']]));

        $this->assertNotNull($first);
        $this->assertSame($first->id, $reply->fresh()->reply_to_message_id);
    }

    public function test_fetch_messages_includes_reply_preview(): void
    {
        [$user, $conversation] = $this->conversation(ChannelType::WHATSAPP);
        $target = $this->target($conversation, 'wamid.target', 'Mensaje original');
        $this->outboundReply($conversation, $user, $target);
        Sanctum::actingAs($user);

        $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.content', 'Respuesta')
            ->assertJsonPath('data.0.reply_to.content', 'Mensaje original');
    }

    public function test_deleted_quoted_message_keeps_quote_without_content_or_media(): void
    {
        [$user, $conversation] = $this->conversation(ChannelType::WHATSAPP);
        $target = $this->target($conversation, 'wamid.target', 'Texto privado');
        $target->update(['message_type' => MessageType::Image, 'media_url' => '/storage/messages/privada.jpg', 'media_filename' => 'privada.jpg']);
        $target->delete();
        $this->outboundReply($conversation, $user, $target);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.reply_to.id', $target->id)
            ->assertJsonPath('data.0.reply_to.message_type', 'image')
            ->assertJsonPath('data.0.reply_to.content', null)
            ->assertJsonPath('data.0.reply_to.media_url', null)
            ->assertJsonPath('data.0.reply_to.media_full_url', null)
            ->assertJsonPath('data.0.reply_to.media_filename', null);

        $this->assertNotNull($response->json('data.0.reply_to.deleted_at'));
    }

    private function outboundReply(Conversation $conversation, User $user, Message $target): Message
    {
        return Message::unguarded(fn () => Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $user->id,
            'content' => 'Respuesta',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => 'wamid.reply',
            'reply_to_message_id' => $target->id,
            'created_at' => now()->addMinute(),
        ]));
    }

    private function target(Conversation $conversation, ?string $externalId, string $content = 'Original'): Message
    {
        return Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $conversation->contact_id,
            'content' => $content,
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'external_id' => $externalId,
        ]);
    }

    /**
     * @param  array<string, string>|null  $context
     * @return array<string, mixed>
     */
    private function whatsAppInbound(string $id, string $body, ?array $context = null): array
    {
        return [
            'value' => [
                'metadata' => ['phone_number_id' => '123456789'],
                'contacts' => [['profile' => ['name' => 'Jane Doe'], 'wa_id' => '5492235112208']],
                'messages' => [[
                    'from' => '5492235112208',
                    'id' => $id,
                    'timestamp' => (string) now()->timestamp,
                    'type' => 'text',
                    'text' => ['body' => $body],
                    ...($context !== null ? ['context' => $context] : []),
                ]],
            ],
        ];
    }

    /**
     * @return array{0: Tenant, 1: Channel}
     */
    private function createWhatsAppChannelContext(): array
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
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
        $channel->update(['whatsapp_config_id' => $config->id]);

        return [$tenant, $channel->fresh()];
    }

    /**
     * @return array{0: User, 1: Conversation}
     */
    private function conversation(ChannelType $type): array
    {
        $tenant = $this->createTenantWithRoles('Acme '.uniqid());
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');
        $attributes = ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'type' => $type, 'name' => $type->value, 'status' => 'active'];
        if ($type === ChannelType::WHATSAPP) {
            $attributes['whatsapp_config_id'] = WhatsAppConfig::create(['phone_number_id' => 'PHONE_'.uniqid(), 'waba_id' => 'WABA_'.uniqid(), 'bussines_token' => Crypt::encryptString('token')])->id;
        } elseif ($type === ChannelType::INSTAGRAM) {
            $attributes['instagram_config_id'] = InstagramConfig::create(['tenant_id' => $tenant->id, 'ig_user_id' => 'IG_'.uniqid(), 'page_id' => 'PAGE_'.uniqid(), 'webhook_object_id' => 'IG_'.uniqid(), 'username' => 'test', 'page_access_token' => Crypt::encryptString('token')])->id;
        } else {
            $attributes['messenger_config_id'] = MessengerConfig::create(['tenant_id' => $tenant->id, 'page_id' => 'PAGE_'.uniqid(), 'page_access_token' => Crypt::encryptString('token')])->id;
        }
        $channel = Channel::create($attributes);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Contact',
            'phone' => '+5491112345678',
            'source' => strtolower($type->value),
            'external_id' => 'CONTACT_'.uniqid(),
        ]);
        $conversation = Conversation::create(['tenant_id' => $tenant->id, 'channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => 'open']);
        $this->receiveInbound($conversation);

        return [$user, $conversation];
    }
}
