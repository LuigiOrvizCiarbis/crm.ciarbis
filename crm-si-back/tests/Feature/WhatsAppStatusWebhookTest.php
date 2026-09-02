<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Events\MessageStatusUpdated;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WhatsAppStatusWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function statusPayload(string $wamid, string $status, array $errors = []): array
    {
        $entry = ['id' => 'wamid'];

        return [
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'statuses' => [array_filter([
                            'id' => $wamid,
                            'status' => $status,
                            'errors' => $errors ?: null,
                        ])],
                    ],
                ]],
            ]],
        ];
    }

    private function makeOutboundMessage(string $wamid): Message
    {
        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WA Test',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Contacto Test',
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
        ]);

        return Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $user->id,
            'content' => '',
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => $wamid,
        ]);
    }

    public function test_failed_status_marks_message_as_failed_with_error(): void
    {
        $message = $this->makeOutboundMessage('wamid.FAIL1');

        $this->postJson('/api/whatsapp-webhook', $this->statusPayload(
            'wamid.FAIL1',
            'failed',
            [[
                'code' => 131053,
                'title' => 'Media upload error',
                'message' => 'Media upload error',
                'error_data' => ['details' => 'Failed to download the media from the provided URL.'],
            ]],
        ))->assertOk();

        $message->refresh();
        $this->assertTrue($message->isFailed());
        $this->assertStringContainsString('131053', $message->error_message);
        $this->assertStringContainsString('Failed to download the media', $message->error_message);
    }

    public function test_delivered_status_sets_delivered_at(): void
    {
        $message = $this->makeOutboundMessage('wamid.DELIV1');

        $this->postJson('/api/whatsapp-webhook', $this->statusPayload('wamid.DELIV1', 'delivered'))
            ->assertOk();

        $message->refresh();
        $this->assertTrue($message->isDelivered());
        $this->assertFalse($message->isFailed());
    }

    public function test_read_status_sets_delivered_and_read(): void
    {
        $message = $this->makeOutboundMessage('wamid.READ1');

        $this->postJson('/api/whatsapp-webhook', $this->statusPayload('wamid.READ1', 'read'))
            ->assertOk();

        $message->refresh();
        $this->assertTrue($message->isDelivered());
        $this->assertTrue($message->isRead());
    }

    public function test_played_status_sets_delivered_read_and_played(): void
    {
        $message = $this->makeOutboundMessage('wamid.PLAY1');

        $this->postJson('/api/whatsapp-webhook', $this->statusPayload('wamid.PLAY1', 'played'))
            ->assertOk();

        $message->refresh();
        // No se puede reproducir un audio sin haber abierto el chat, así que
        // `played` implica los dos estados anteriores aunque no hayan llegado.
        $this->assertTrue($message->isDelivered());
        $this->assertTrue($message->isRead());
        $this->assertTrue($message->isPlayed());
    }

    public function test_played_status_is_not_reapplied_on_duplicate_webhook(): void
    {
        Event::fake([MessageStatusUpdated::class]);
        $message = $this->makeOutboundMessage('wamid.PLAY2');

        $this->postJson('/api/whatsapp-webhook', $this->statusPayload('wamid.PLAY2', 'played'))
            ->assertOk();
        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);

        $playedAt = $message->refresh()->played_at;

        // Meta reenvía statuses duplicados: no debe re-emitir ni pisar el timestamp.
        $this->postJson('/api/whatsapp-webhook', $this->statusPayload('wamid.PLAY2', 'played'))
            ->assertOk();

        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);
        $this->assertEquals($playedAt, $message->refresh()->played_at);
    }

    public function test_read_status_after_delivered_is_skipped_when_already_read(): void
    {
        // Meta documenta que `delivered` puede no llegar nunca cuando el usuario
        // lee con el chat abierto. El estado se deriva, no se asume secuencial.
        $message = $this->makeOutboundMessage('wamid.ORDER1');

        $this->postJson('/api/whatsapp-webhook', $this->statusPayload('wamid.ORDER1', 'read'))
            ->assertOk();

        $message->refresh();
        $readAt = $message->read_at;
        $this->assertTrue($message->isDelivered());

        // Un `delivered` tardío no debe pisar el read_at ya registrado.
        $this->postJson('/api/whatsapp-webhook', $this->statusPayload('wamid.ORDER1', 'delivered'))
            ->assertOk();

        $this->assertEquals($readAt, $message->refresh()->read_at);
    }

    public function test_status_change_broadcasts_event_once_and_not_on_duplicates(): void
    {
        Event::fake([MessageStatusUpdated::class]);
        $message = $this->makeOutboundMessage('wamid.BCAST1');

        $this->postJson('/api/whatsapp-webhook', $this->statusPayload('wamid.BCAST1', 'delivered'))
            ->assertOk();

        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);

        // Reenvío del mismo status: ya está delivered, no debe re-emitir.
        $this->postJson('/api/whatsapp-webhook', $this->statusPayload('wamid.BCAST1', 'delivered'))
            ->assertOk();

        Event::assertDispatchedTimes(MessageStatusUpdated::class, 1);
    }

    public function test_status_for_unknown_wamid_is_ignored_without_error(): void
    {
        $this->postJson('/api/whatsapp-webhook', $this->statusPayload('wamid.UNKNOWN', 'failed', [[
            'code' => 131053,
            'error_data' => ['details' => 'x'],
        ]]))->assertOk();

        $this->assertDatabaseCount('messages', 0);
    }
}
