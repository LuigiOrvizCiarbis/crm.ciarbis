<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Events\TenantMessageReceived;
use App\Jobs\GenerateAiReplyJob;
use App\Models\AiConfig;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InstagramConfig;
use App\Models\Message;
use App\Models\MessengerConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessengerWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/messenger-webhook';

    private const APP_SECRET = 'test-app-secret';

    private const VERIFY_TOKEN = 'test-verify-token';

    private const PAGE_ID = 'PAGE_1';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.facebook.app_secret', self::APP_SECRET);
        config()->set('services.facebook.verify_token', self::VERIFY_TOKEN);
        config()->set('services.facebook.graph_version', 'v21.0');
    }

    public function test_get_verify_with_correct_token_returns_challenge(): void
    {
        $response = $this->get(self::ENDPOINT.'?hub_mode=subscribe&hub_verify_token='.self::VERIFY_TOKEN.'&hub_challenge=CH123');

        $response->assertOk();
        $this->assertSame('CH123', $response->getContent());
    }

    public function test_get_verify_with_wrong_token_returns_403(): void
    {
        $this->get(self::ENDPOINT.'?hub_mode=subscribe&hub_verify_token=WRONG&hub_challenge=CH123')
            ->assertStatus(403);
    }

    public function test_post_with_invalid_signature_returns_403(): void
    {
        $this->createChannel();

        $payload = $this->messagingPayload('PSID_1', 'hola', 'mid_1');
        $this->postWebhook($payload, 'sha256=deadbeef')->assertStatus(403);

        $this->assertSame(0, Message::count());
    }

    public function test_inbound_text_creates_contact_conversation_and_message(): void
    {
        Event::fake([MessageSent::class, TenantMessageReceived::class]);
        Queue::fake();
        Http::fake(); // el hydrate de perfil no debe romper

        $this->createChannel();

        $this->postWebhook($this->messagingPayload('PSID_1', 'hola', 'mid_1'))->assertOk();

        $contact = Contact::first();
        $this->assertSame('facebook', $contact->source);
        $this->assertSame('PSID_1', $contact->external_id);

        $message = Message::first();
        $this->assertSame('hola', $message->content);
        $this->assertSame(MessageDirection::INBOUND, $message->direction);
        $this->assertSame('mid_1', $message->external_id);

        Event::assertDispatched(MessageSent::class);
        Event::assertDispatched(TenantMessageReceived::class);
        Queue::assertNotPushed(GenerateAiReplyJob::class);
    }

    public function test_contact_name_is_hydrated_from_profile(): void
    {
        Event::fake();
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'first_name' => 'Ana',
                'last_name' => 'Pérez',
            ], 200),
        ]);

        $this->createChannel();

        $this->postWebhook($this->messagingPayload('PSID_1', 'hola', 'mid_prof'))->assertOk();

        $this->assertSame('Ana Pérez', Contact::first()->name);
    }

    public function test_inbound_dispatches_ai_reply_when_autoreply_enabled(): void
    {
        Event::fake();
        Queue::fake();
        Http::fake();

        [$channel, $tenant] = $this->createChannel(aiDefault: true);

        AiConfig::create([
            'tenant_id' => $tenant->id,
            'provider' => 'claude',
            'api_key' => Crypt::encryptString('sk-test'),
            'model' => 'claude-sonnet-5',
            'enabled' => true,
        ]);

        $this->postWebhook($this->messagingPayload('PSID_1', 'hola bot', 'mid_ai'))->assertOk();

        $this->assertTrue((bool) Conversation::first()->ai_autoreply_enabled);
        Queue::assertPushed(GenerateAiReplyJob::class);
    }

    public function test_echo_is_stored_as_outbound_and_disables_autoreply(): void
    {
        Event::fake();
        Queue::fake();
        Http::fake();

        $this->createChannel(aiDefault: true);

        // Echo: sender = página, recipient = contacto.
        $this->postWebhook($this->echoPayload('PSID_1', 'respondo yo', 'mid_echo'))->assertOk();

        $message = Message::first();
        $this->assertSame(MessageDirection::OUTBOUND, $message->direction);

        // Handoff: intervino un humano desde la app de Messenger.
        $this->assertFalse((bool) Conversation::first()->ai_autoreply_enabled);
        Queue::assertNotPushed(GenerateAiReplyJob::class);
    }

    public function test_inbound_image_attachment_is_downloaded(): void
    {
        Event::fake();
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/*' => Http::response('BINARYDATA', 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response([], 200),
        ]);

        [$channel, $tenant] = $this->createChannel();

        $payload = $this->attachmentPayload('PSID_1', 'image', 'https://cdn.example.com/pic.jpg', 'mid_img');
        $this->postWebhook($payload)->assertOk();

        $message = Message::first();
        $this->assertSame(MessageType::Image, $message->message_type);
        $this->assertStringContainsString("messages/{$tenant->id}/", $message->media_url);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $message->media_url));
    }

    /**
     * `file` es un tipo de attachment que Instagram no tiene.
     */
    public function test_file_attachment_is_stored_as_document(): void
    {
        Event::fake();
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.com/*' => Http::response('PDFDATA', 200, ['Content-Type' => 'application/pdf']),
            '*' => Http::response([], 200),
        ]);

        $this->createChannel();

        $payload = $this->attachmentPayload('PSID_1', 'file', 'https://cdn.example.com/doc.pdf', 'mid_file');
        $this->postWebhook($payload)->assertOk();

        $message = Message::first();
        $this->assertSame(MessageType::Document, $message->message_type);
        $this->assertStringEndsWith('.pdf', $message->media_url);
    }

    public function test_duplicate_mid_is_deduped(): void
    {
        Event::fake();
        Http::fake();

        $this->createChannel();

        $payload = $this->messagingPayload('PSID_1', 'hola', 'mid_dup');
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(1, Message::where('external_id', 'mid_dup')->count());
    }

    /**
     * Los eventos standby llegan cuando otra app tiene el control del thread.
     * Procesarlos duplicaría los mensajes que ya entran por messaging[].
     */
    public function test_standby_events_are_ignored(): void
    {
        Event::fake();
        Http::fake();

        $this->createChannel();

        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => self::PAGE_ID,
                'time' => now()->timestamp,
                'standby' => [[
                    'sender' => ['id' => 'PSID_1'],
                    'recipient' => ['id' => self::PAGE_ID],
                    'message' => ['mid' => 'mid_standby', 'text' => 'no procesar'],
                ]],
            ]],
        ];

        $this->postWebhook($payload)->assertOk();

        $this->assertSame(0, Message::count());
    }

    /**
     * Aislamiento: un evento de Instagram posteado a este webhook se descarta.
     */
    public function test_instagram_event_posted_to_messenger_webhook_is_ignored(): void
    {
        Event::fake();
        Http::fake();

        $this->createChannel();

        $payload = $this->messagingPayload('PSID_1', 'hola', 'mid_ig');
        $payload['object'] = 'instagram';

        $this->postWebhook($payload)->assertOk();

        $this->assertSame(0, Message::count());
        $this->assertSame(0, Contact::count());
    }

    /**
     * El caso que motivó el fix de aislamiento: una misma página de Facebook con
     * Instagram vinculado Y Messenger conectado. El evento de Messenger debe
     * quedar en el canal FACEBOOK y no tocar la config de Instagram.
     */
    public function test_shared_page_does_not_leak_into_instagram_channel(): void
    {
        Event::fake();
        Http::fake();

        [$channel, $tenant] = $this->createChannel();

        // Misma página, ya conectada como canal de Instagram.
        $igConfig = InstagramConfig::create([
            'tenant_id' => $tenant->id,
            'ig_user_id' => 'IG_BIZ_1',
            'page_id' => self::PAGE_ID,
            'webhook_object_id' => 'IG_BIZ_1',
            'username' => 'acme',
            'page_access_token' => Crypt::encryptString('IG_TOKEN'),
        ]);

        $igChannel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $channel->user_id,
            'instagram_config_id' => $igConfig->id,
            'type' => ChannelType::INSTAGRAM,
            'external_id' => 'IG_BIZ_1',
            'name' => '@acme',
            'status' => 'active',
        ]);

        $this->postWebhook($this->messagingPayload('PSID_1', 'hola', 'mid_shared'))->assertOk();

        $message = Message::first();
        $this->assertNotNull($message);

        $conversation = Conversation::find($message->conversation_id);
        $this->assertSame($channel->id, $conversation->channel_id);
        $this->assertNotSame($igChannel->id, $conversation->channel_id);

        $this->assertSame('facebook', Contact::first()->source);

        // La config de Instagram no fue tocada.
        $this->assertSame('IG_BIZ_1', $igConfig->refresh()->webhook_object_id);
    }

    public function test_unknown_page_is_ignored(): void
    {
        Event::fake();
        Http::fake();

        $this->createChannel();

        $payload = $this->messagingPayload('PSID_1', 'hola', 'mid_unknown');
        $payload['entry'][0]['id'] = 'PAGE_DESCONOCIDA';
        $payload['entry'][0]['messaging'][0]['recipient']['id'] = 'PAGE_DESCONOCIDA';

        $this->postWebhook($payload)->assertOk();

        $this->assertSame(0, Message::count());
    }

    // ---------------------------------------------------------------------

    /**
     * @return array{0: Channel, 1: Tenant, 2: MessengerConfig}
     */
    private function createChannel(bool $aiDefault = false): array
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);

        $config = MessengerConfig::create([
            'tenant_id' => $tenant->id,
            'page_id' => self::PAGE_ID,
            'page_name' => 'Acme SRL',
            'page_access_token' => Crypt::encryptString('PAGE_TOKEN'),
            'ai_autoreply_default' => $aiDefault,
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'messenger_config_id' => $config->id,
            'type' => ChannelType::FACEBOOK,
            'external_id' => self::PAGE_ID,
            'name' => 'Acme SRL',
            'status' => 'active',
        ]);

        return [$channel, $tenant, $config];
    }

    private function postWebhook(array $payload, ?string $signature = null)
    {
        $body = json_encode($payload);
        $signature ??= 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET);

        return $this->call(
            'POST',
            self::ENDPOINT,
            [],
            [],
            [],
            ['HTTP_X-Hub-Signature-256' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $body,
        );
    }

    private function messagingPayload(string $psid, string $text, string $mid): array
    {
        return $this->wrapEvent([
            'sender' => ['id' => $psid],
            'recipient' => ['id' => self::PAGE_ID],
            'message' => ['mid' => $mid, 'text' => $text],
        ]);
    }

    private function echoPayload(string $psid, string $text, string $mid): array
    {
        return $this->wrapEvent([
            'sender' => ['id' => self::PAGE_ID],
            'recipient' => ['id' => $psid],
            'message' => ['mid' => $mid, 'text' => $text, 'is_echo' => true],
        ]);
    }

    private function attachmentPayload(string $psid, string $type, string $url, string $mid): array
    {
        return $this->wrapEvent([
            'sender' => ['id' => $psid],
            'recipient' => ['id' => self::PAGE_ID],
            'message' => [
                'mid' => $mid,
                'attachments' => [[
                    'type' => $type,
                    'payload' => ['url' => $url],
                ]],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function wrapEvent(array $event): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => self::PAGE_ID,
                'time' => now()->timestamp,
                'messaging' => [$event],
            ]],
        ];
    }
}
