<?php

namespace Tests\Feature;

use App\Enums\ScheduledMessageStatus;
use App\Jobs\SendScheduledMessageJob;
use App\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsMessengerConversation;
use Tests\Concerns\RequiresPostgresTimestamptz;
use Tests\TestCase;

/**
 * El front manda `scheduled_for` en ISO UTC y PHP corre en hora argentina:
 * si la columna timestamptz perdiera el offset, el mensaje saldría 3 horas
 * antes o después. Sólo Postgres real puede detectarlo.
 *
 * php artisan test -c phpunit.postgres.xml --group=requires-postgres
 */
#[Group('requires-postgres')]
class ScheduledMessageTimezoneTest extends TestCase
{
    use BuildsMessengerConversation;
    use RefreshDatabase;
    use RequiresPostgresTimestamptz;

    public function test_message_scheduled_in_two_hours_is_not_dispatched_early(): void
    {
        Queue::fake();
        [$user, $conversation] = $this->createMessengerConversation();
        Sanctum::actingAs($user);

        $this->postJson("/api/conversations/{$conversation->id}/scheduled-messages", [
            'type' => 'text',
            'content' => 'Más tarde',
            'scheduled_for' => now()->utc()->addHours(2)->toIso8601String(),
        ])->assertCreated();

        $this->artisan('messages:dispatch-scheduled')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(ScheduledMessageStatus::Scheduled, ScheduledMessage::firstOrFail()->status);
    }

    public function test_local_time_in_the_past_is_dispatched(): void
    {
        Queue::fake();
        [$user, $conversation] = $this->createMessengerConversation();
        $scheduled = ScheduledMessage::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'message_type' => 'text',
            'content' => 'Ya',
            // now() local (America/Argentina), como lo escribiría cualquier código del back.
            'scheduled_for' => now()->subMinute(),
        ]);

        $this->artisan('messages:dispatch-scheduled')->assertSuccessful();

        Queue::assertPushed(SendScheduledMessageJob::class, fn ($job) => $job->scheduledMessageId === $scheduled->id);
    }
}
