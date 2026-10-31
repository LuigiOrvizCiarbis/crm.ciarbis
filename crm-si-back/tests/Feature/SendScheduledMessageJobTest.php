<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\ScheduledMessageStatus;
use App\Events\ScheduledMessageUpdated;
use App\Jobs\SendScheduledMessageJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMessengerConversation;
use Tests\TestCase;

class SendScheduledMessageJobTest extends TestCase
{
    use BuildsMessengerConversation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.facebook.app_secret', 'test-app-secret');
        config()->set('services.facebook.graph_version', 'v21.0');
        Event::fake([ScheduledMessageUpdated::class]);
    }

    public function test_sends_the_message_and_links_it(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/PAGE_1/messages' => Http::response(['message_id' => 'fb_mid_1'], 200),
        ]);
        [$user, $conversation] = $this->createMessengerConversation();
        $scheduled = $this->queuedMessage($user, $conversation);

        SendScheduledMessageJob::dispatchSync($scheduled->id);

        $scheduled->refresh();
        $message = Message::firstOrFail();
        $this->assertSame(ScheduledMessageStatus::Sent, $scheduled->status);
        $this->assertSame($message->id, $scheduled->message_id);
        $this->assertNotNull($scheduled->sent_at);
        $this->assertSame('Recordatorio', $message->content);
        $this->assertSame($user->id, (int) $message->sender_id);
        Http::assertSent(fn ($request) => $request['message']['text'] === 'Recordatorio');
        Event::assertDispatched(ScheduledMessageUpdated::class);
    }

    public function test_closed_window_marks_failed_and_notifies_the_author(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/PAGE_1/messages' => Http::response([
                'error' => ['code' => 10, 'error_subcode' => 2534022, 'message' => 'outside of allowed window'],
            ], 400),
        ]);
        [$user, $conversation] = $this->createMessengerConversation();
        $scheduled = $this->queuedMessage($user, $conversation);

        SendScheduledMessageJob::dispatchSync($scheduled->id);

        $scheduled->refresh();
        $this->assertSame(ScheduledMessageStatus::Failed, $scheduled->status);
        $this->assertSame('window_closed', $scheduled->error_reason);
        $this->assertStringContainsString('24 horas', $scheduled->error_message);
        $this->assertSame(0, Message::count());
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $user->id)
            ->where('type', NotificationType::SCHEDULED_MESSAGE_FAILED->value)
            ->exists());
    }

    public function test_disconnected_channel_marks_failed_and_notifies_the_author(): void
    {
        Http::fake();
        [$user, $conversation] = $this->createMessengerConversation();
        $scheduled = $this->queuedMessage($user, $conversation);
        $conversation->channel()->update(['status' => 'disconnected']);

        SendScheduledMessageJob::dispatchSync($scheduled->id);

        $scheduled->refresh();
        $this->assertSame(ScheduledMessageStatus::Failed, $scheduled->status);
        $this->assertSame('channel_unavailable', $scheduled->error_reason);
        $this->assertStringContainsString('desconectado', $scheduled->error_message);
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $user->id)
            ->where('type', NotificationType::SCHEDULED_MESSAGE_FAILED->value)
            ->exists());
        Http::assertNothingSent();
    }

    public function test_unexpected_job_failure_marks_failed_and_notifies_the_author(): void
    {
        [$user, $conversation] = $this->createMessengerConversation();
        $scheduled = $this->queuedMessage($user, $conversation);
        $scheduled->update(['status' => ScheduledMessageStatus::Sending]);

        // Lo que hace el worker ante un timeout o una excepción no capturada.
        (new SendScheduledMessageJob($scheduled->id))->failed(new \RuntimeException('timeout'));

        $scheduled->refresh();
        $this->assertSame(ScheduledMessageStatus::Failed, $scheduled->status);
        $this->assertSame('unknown', $scheduled->error_reason);
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $user->id)
            ->where('type', NotificationType::SCHEDULED_MESSAGE_FAILED->value)
            ->exists());
        Event::assertDispatched(ScheduledMessageUpdated::class);
    }

    public function test_author_removed_from_workspace_does_not_send(): void
    {
        Http::fake();
        [$user, $conversation] = $this->createMessengerConversation();
        $scheduled = $this->queuedMessage($user, $conversation);
        TenantMembership::query()->where('user_id', $user->id)->update(['removed_at' => now()]);

        SendScheduledMessageJob::dispatchSync($scheduled->id);

        $this->assertSame('permission_lost', $scheduled->refresh()->error_reason);
        Http::assertNothingSent();
    }

    public function test_only_claims_queued_messages(): void
    {
        Http::fake();
        [$user, $conversation] = $this->createMessengerConversation();
        $scheduled = $this->queuedMessage($user, $conversation);
        $scheduled->update(['status' => ScheduledMessageStatus::Sending]);

        SendScheduledMessageJob::dispatchSync($scheduled->id);

        $this->assertSame(ScheduledMessageStatus::Sending, $scheduled->refresh()->status);
        Http::assertNothingSent();
    }

    private function queuedMessage(User $user, Conversation $conversation): ScheduledMessage
    {
        return ScheduledMessage::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'message_type' => 'text',
            'content' => 'Recordatorio',
            'scheduled_for' => now()->utc()->subMinute(),
            'status' => ScheduledMessageStatus::Queued,
            'queued_at' => now()->utc(),
        ]);
    }
}
