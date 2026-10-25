<?php

namespace Tests\Feature\Console;

use App\Enums\ScheduledMessageStatus;
use App\Jobs\SendScheduledMessageJob;
use App\Models\ScheduledMessage;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsMessengerConversation;
use Tests\TestCase;

class DispatchScheduledMessagesTest extends TestCase
{
    use BuildsMessengerConversation;
    use RefreshDatabase;

    public function test_queues_only_due_messages(): void
    {
        Queue::fake();
        [$user, $conversation] = $this->createMessengerConversation();
        $due = $this->makeScheduled($user, $conversation, now()->utc()->subMinute());
        $future = $this->makeScheduled($user, $conversation, now()->utc()->addHour());
        $cancelled = $this->makeScheduled($user, $conversation, now()->utc()->subMinute(), ScheduledMessageStatus::Cancelled);

        $this->artisan('messages:dispatch-scheduled')->assertSuccessful();

        $this->assertSame(ScheduledMessageStatus::Queued, $due->refresh()->status);
        $this->assertNotNull($due->queued_at);
        $this->assertSame(ScheduledMessageStatus::Scheduled, $future->refresh()->status);
        $this->assertSame(ScheduledMessageStatus::Cancelled, $cancelled->refresh()->status);
        Queue::assertPushed(SendScheduledMessageJob::class, 1);
        Queue::assertPushed(SendScheduledMessageJob::class, fn ($job) => $job->scheduledMessageId === $due->id);
    }

    public function test_requeues_messages_stuck_in_queued(): void
    {
        Queue::fake();
        [$user, $conversation] = $this->createMessengerConversation();
        $stale = $this->makeScheduled($user, $conversation, now()->utc()->subHour(), ScheduledMessageStatus::Queued, now()->utc()->subMinutes(10));
        $recent = $this->makeScheduled($user, $conversation, now()->utc()->subMinute(), ScheduledMessageStatus::Queued, now()->utc());

        $this->artisan('messages:dispatch-scheduled')->assertSuccessful();

        Queue::assertPushed(SendScheduledMessageJob::class, 1);
        Queue::assertPushed(SendScheduledMessageJob::class, fn ($job) => $job->scheduledMessageId === $stale->id);
        $this->assertTrue($stale->refresh()->queued_at->greaterThan(now()->utc()->subMinute()));
        $this->assertSame(ScheduledMessageStatus::Queued, $recent->refresh()->status);
    }

    private function makeScheduled(
        $user,
        $conversation,
        CarbonInterface $scheduledFor,
        ScheduledMessageStatus $status = ScheduledMessageStatus::Scheduled,
        $queuedAt = null,
    ): ScheduledMessage {
        return ScheduledMessage::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'message_type' => 'text',
            'content' => 'Hola',
            'scheduled_for' => $scheduledFor,
            'status' => $status,
            'queued_at' => $queuedAt,
        ]);
    }
}
