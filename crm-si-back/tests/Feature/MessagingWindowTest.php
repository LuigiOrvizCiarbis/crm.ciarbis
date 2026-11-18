<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ScheduledMessageStatus;
use App\Events\ScheduledMessageUpdated;
use App\Jobs\SendScheduledMessageJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Models\WhatsAppConfig;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsInstagramConversation;
use Tests\Concerns\BuildsMessengerConversation;
use Tests\TestCase;

/**
 * Ventana de atención de Meta antes de enviar: texto libre sólo dentro de 24 h
 * del último entrante; HUMAN_AGENT (7 días) sólo para personas en IG/Messenger
 * y con el flag prendido.
 */
class MessagingWindowTest extends TestCase
{
    use BuildsInstagramConversation;
    use BuildsMessengerConversation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.facebook.graph_version', 'v21.0');
        config()->set('services.meta.human_agent_enabled', false);
    }

    public function test_whatsapp_without_inbound_is_blocked_before_calling_meta(): void
    {
        Http::fake();
        [$user, $conversation] = $this->createWhatsAppConversation();
        Sanctum::actingAs($user);
        $messagesBefore = Message::count();

        $response = $this->sendText($conversation);

        $response->assertStatus(422);
        $this->assertStringContainsString('plantilla aprobada', $response->json('message'));
        $this->assertSame($messagesBefore, Message::count());
        Http::assertNothingSent();
    }

    public function test_whatsapp_inside_the_24h_window_sends(): void
    {
        Http::fake(['https://graph.facebook.com/*/*/messages' => Http::response(['messages' => [['id' => 'wamid.ok']]], 200)]);
        [$user, $conversation] = $this->createWhatsAppConversation();
        $this->receiveInbound($conversation, now()->subHours(23));
        Sanctum::actingAs($user);

        $this->sendText($conversation)->assertStatus(201);
    }

    public function test_messenger_after_24h_is_blocked_while_human_agent_is_disabled(): void
    {
        Http::fake();
        $conversation = $this->messengerConversationWithInboundAt(now()->subHours(30));

        $response = $this->sendText($conversation);

        $response->assertStatus(422);
        $this->assertStringContainsString('ventana de 24 horas', $response->json('message'));
        Http::assertNothingSent();
    }

    public function test_messenger_after_24h_uses_human_agent_tag_when_enabled(): void
    {
        config()->set('services.meta.human_agent_enabled', true);
        Http::fake(['https://graph.facebook.com/*/PAGE_1/messages' => Http::response(['message_id' => 'fb_mid_1'], 200)]);
        $conversation = $this->messengerConversationWithInboundAt(now()->subHours(30));

        $this->sendText($conversation)->assertStatus(201);

        Http::assertSent(fn ($request) => $request['messaging_type'] === 'MESSAGE_TAG'
            && $request['tag'] === 'HUMAN_AGENT');
    }

    public function test_messenger_after_7_days_is_blocked_even_with_human_agent(): void
    {
        config()->set('services.meta.human_agent_enabled', true);
        Http::fake();
        $conversation = $this->messengerConversationWithInboundAt(now()->subDays(8));

        $this->sendText($conversation)->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_messenger_inside_24h_responds_without_tag_even_with_human_agent(): void
    {
        config()->set('services.meta.human_agent_enabled', true);
        Http::fake(['https://graph.facebook.com/*/PAGE_1/messages' => Http::response(['message_id' => 'fb_mid_1'], 200)]);
        $conversation = $this->messengerConversationWithInboundAt(now()->subHours(2));

        $this->sendText($conversation)->assertStatus(201);

        Http::assertSent(fn ($request) => $request['messaging_type'] === 'RESPONSE'
            && ! isset($request['tag']));
    }

    public function test_instagram_after_24h_uses_human_agent_tag_when_enabled(): void
    {
        config()->set('services.meta.human_agent_enabled', true);
        Http::fake(['https://graph.facebook.com/*/PAGE_1/messages' => Http::response(['message_id' => 'ig_mid_1'], 200)]);
        [$user, $conversation] = $this->createInstagramConversation();
        $this->moveInboundTo($conversation, now()->subHours(30));
        Sanctum::actingAs($user);

        $this->sendText($conversation)->assertStatus(201);

        Http::assertSent(fn ($request) => $request['messaging_type'] === 'MESSAGE_TAG'
            && $request['tag'] === 'HUMAN_AGENT');
    }

    public function test_scheduled_message_never_uses_human_agent(): void
    {
        config()->set('services.meta.human_agent_enabled', true);
        Event::fake([ScheduledMessageUpdated::class]);
        Http::fake();
        [$user, $conversation] = $this->createMessengerConversation();
        $this->moveInboundTo($conversation, now()->subHours(30));
        $scheduled = ScheduledMessage::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'message_type' => 'text',
            'content' => 'Recordatorio',
            'scheduled_for' => now()->utc()->subMinute(),
            'status' => ScheduledMessageStatus::Queued,
            'queued_at' => now()->utc(),
        ]);

        SendScheduledMessageJob::dispatchSync($scheduled->id);

        $scheduled->refresh();
        $this->assertSame(ScheduledMessageStatus::Failed, $scheduled->status);
        $this->assertSame('window_closed', $scheduled->error_reason);
        Http::assertNothingSent();
    }

    private function sendText(Conversation $conversation): TestResponse
    {
        return $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'Hola de nuevo',
        ]);
    }

    private function messengerConversationWithInboundAt(CarbonInterface $at): Conversation
    {
        [$user, $conversation] = $this->createMessengerConversation();
        $this->moveInboundTo($conversation, $at);
        Sanctum::actingAs($user);

        return $conversation;
    }

    /**
     * Los fixtures arrancan con un entrante recién recibido; acá se lo corre en
     * el tiempo para ubicar la conversación en el tramo de ventana a probar.
     */
    private function moveInboundTo(Conversation $conversation, CarbonInterface $at): void
    {
        Message::query()->where('conversation_id', $conversation->id)->inbound()
            ->update(['created_at' => $at, 'updated_at' => $at]);
    }

    /**
     * Conversación de WhatsApp sin mensajes: el contacto nunca escribió.
     *
     * @return array{0: User, 1: Conversation}
     */
    private function createWhatsAppConversation(): array
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');

        $config = WhatsAppConfig::create([
            'phone_number_id' => 'PHONE_'.uniqid(),
            'waba_id' => 'WABA_'.uniqid(),
            'bussines_token' => Crypt::encryptString('token'),
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'whatsapp_config_id' => $config->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fulano WA',
            'phone' => '+5491112345678',
            'source' => 'whatsapp',
        ]);

        return [$user, Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ])];
    }
}
