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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
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

    /**
     * Fixture real generada con ffmpeg (audio sólo-audio en contenedor webm/opus).
     * PHP/fileinfo la detecta como video/webm (contenedor sólo-audio marcado
     * video), justo el caso que rompía el envío desde Chrome/Android antes del fix.
     */
    private function webmAudioFixturePath(): string
    {
        return __DIR__.'/../Fixtures/audio/audio-sample.webm';
    }

    /** Fixture m4a/aac real; fileinfo la detecta como audio/x-m4a (Safari/iOS, notas de voz de WhatsApp). */
    private function m4aAudioFixturePath(): string
    {
        return __DIR__.'/../Fixtures/audio/audio-sample.m4a';
    }

    /**
     * Crea tenant (con roles Spatie provisionados), usuario Owner, canal
     * WhatsApp y conversación lista para enviar audio. Las policies autorizan
     * por permisos de Spatie: sin `assignRole('Owner')` el endpoint responde 403.
     */
    private function createWhatsAppConversationForAudioTests(): array
    {
        $tenant = $this->createTenantWithRoles();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        $user->assignRole('Owner');

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

        return [$user, $conversation];
    }

    public function test_webm_audio_from_mobile_recorder_is_accepted_and_transcoded_before_whatsapp_upload(): void
    {
        [$user, $conversation] = $this->createWhatsAppConversationForAudioTests();

        Http::fake([
            'https://graph.facebook.com/*/media' => Http::response(['id' => 'media_123'], 200),
            'https://graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid_123']]], 200),
        ]);

        // ffmpeg no está instalado en el container de test todavía (requiere
        // rebuild de imagen, agregado al Dockerfile en este cambio); simulamos
        // una conversión exitosa a ogg/opus para no acoplar este test a la
        // infraestructura del container. El fake de "which" resuelve OK
        // (ffmpeg "disponible") y el de "ffmpeg" crea el .ogg de salida, que es
        // lo que el servicio verifica con is_file() después de correr.
        Process::fake(function ($process) {
            $command = $process->command;

            if (is_array($command) && ($command[0] ?? null) === 'which') {
                return Process::result(exitCode: 0);
            }

            if (is_array($command) && ($command[0] ?? null) === 'ffmpeg') {
                $outputPath = end($command);
                file_put_contents($outputPath, 'fake-ogg-content');

                return Process::result('');
            }

            return Process::result(exitCode: 1);
        });

        Sanctum::actingAs($user);

        $response = $this->post('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'audio',
            'audio' => new UploadedFile($this->webmAudioFixturePath(), 'nota-de-voz.webm', 'video/webm', null, true),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);

        $this->assertSame(1, Message::query()->where('message_type', MessageType::Audio)->count());

        // El upload a Meta debe llevar el mime convertido (ogg), no el webm original.
        // Es un request multipart: data() devuelve las partes como lista
        // [{name, contents}, ...], no un array asociativo.
        Http::assertSent(function ($request) {
            $typePart = collect($request->data())->firstWhere('name', 'type');

            return str_contains($request->url(), '/media')
                && ($typePart['contents'] ?? null) === 'audio/ogg';
        });
    }

    public function test_x_m4a_audio_is_accepted_without_transcoding(): void
    {
        [$user, $conversation] = $this->createWhatsAppConversationForAudioTests();

        Http::fake([
            'https://graph.facebook.com/*/media' => Http::response(['id' => 'media_456'], 200),
            'https://graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid_456']]], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->post('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'audio',
            'audio' => new UploadedFile($this->m4aAudioFixturePath(), 'nota-de-voz.m4a', 'audio/x-m4a', null, true),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $this->assertSame(1, Message::query()->where('message_type', MessageType::Audio)->count());

        // M4A no necesita transcodificación, pero Meta exige el MIME estándar
        // audio/mp4 en el multipart y en el campo type.
        Http::assertSent(function ($request) {
            $typePart = collect($request->data())->firstWhere('name', 'type');

            return str_contains($request->url(), '/media')
                && ($typePart['contents'] ?? null) === 'audio/mp4';
        });
    }

    public function test_unsupported_audio_format_is_rejected_with_clear_message(): void
    {
        [$user, $conversation] = $this->createWhatsAppConversationForAudioTests();

        Sanctum::actingAs($user);

        $response = $this->post('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'audio',
            'audio' => UploadedFile::fake()->createWithContent('documento.pdf', '%PDF-1.4 fake content'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertStringContainsString('formato de audio', $response->json('message'));
        $this->assertSame(0, Message::count());
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

    public function test_inbound_reply_marks_previous_outbound_messages_as_read_when_meta_omits_read_status(): void
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

        $outbound = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $channel->user_id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => 'wamid.outbound-without-read',
            'delivered_at' => now()->subSecond(),
        ]);

        app(WhatsAppMessageService::class)->processIncomingMessage([
            'value' => [
                'metadata' => ['phone_number_id' => '123456789'],
                'messages' => [[
                    'from' => '5492235112208',
                    'id' => 'wamid.inbound-reply',
                    'timestamp' => (string) now()->timestamp,
                    'type' => 'text',
                    'text' => ['body' => 'Respuesta'],
                ]],
            ],
        ]);

        $this->assertNotNull($outbound->fresh()->read_at);
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
