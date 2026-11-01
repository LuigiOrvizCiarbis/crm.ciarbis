<?php

namespace Tests\Feature\Api;

use App\Enums\AiBookingStatus;
use App\Enums\TaskStatus;
use App\Jobs\SyncTaskCalendarEventJob;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\AiSchedulingFixtures;
use Tests\TestCase;

/**
 * Mover una reunión desde PUT /api/tasks/{id}: horario futuro, lock del
 * responsable y sin pisar otra reunión o evento sin confirmación.
 */
class TaskMeetingRescheduleTest extends TestCase
{
    use AiSchedulingFixtures, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->freezeMonday();
        [$this->tenant, $this->owner] = $this->makeTenantWithOwner();
        $this->connectGoogle($this->owner);
        $this->conversation = $this->makeConversation($this->tenant, $this->owner, $this->owner->id);
        Sanctum::actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        $this->resetSchedulingFakes();

        parent::tearDown();
    }

    public function test_moving_a_meeting_checks_google_and_withdraws_the_customers_pending_request(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner, '2026-07-21 11:00', AiBookingStatus::CONFIRMED);
        Task::withoutGlobalScopes()->whereKey($task->id)->update([
            'ai_reschedule_starts_at' => '2026-07-22 10:00:00',
            'ai_reschedule_ends_at' => '2026-07-22 10:30:00',
        ]);
        $google = $this->mockGoogle([$this->googleEvents()]);

        $this->putJson("/api/tasks/{$task->id}", $this->interval('2026-07-21 10:00'))->assertOk();

        $task->refresh();
        $this->assertTrue($task->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 10:00', self::TZ)));
        $this->assertNull($task->ai_reschedule_starts_at);
        $this->assertNull($task->ai_reschedule_ends_at);
        $this->assertSame(0, $google->count(), 'The owner calendar is read before saving.');
        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->taskId === $task->id && $job->action === 'upsert');
    }

    public function test_moving_a_meeting_into_the_past_is_rejected(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner, '2026-07-21 11:00', AiBookingStatus::CONFIRMED);
        $this->mockGoogle([]);

        $this->putJson("/api/tasks/{$task->id}", $this->interval('2026-07-20 07:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);

        $this->assertTrue($task->fresh()->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 11:00', self::TZ)));
    }

    public function test_overlap_requires_confirmation_then_saves(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner, '2026-07-21 11:00', AiBookingStatus::CONFIRMED);
        $this->makePendingBooking($this->makeConversation($this->tenant, $this->owner), $this->owner, '2026-07-21 10:00');
        $this->mockGoogle([$this->googleEvents(), $this->googleEvents()]);

        $this->putJson("/api/tasks/{$task->id}", $this->interval('2026-07-21 10:15'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'overlap_confirmation_required');
        $this->assertTrue($task->fresh()->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 11:00', self::TZ)));
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);

        $this->putJson("/api/tasks/{$task->id}", [...$this->interval('2026-07-21 10:15'), 'confirm_overlap' => true])->assertOk();
        $this->assertTrue($task->fresh()->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 10:15', self::TZ)));
    }

    public function test_owner_without_google_is_checked_against_crm_meetings_only(): void
    {
        $other = $this->makeUser($this->tenant);
        $task = $this->makePendingBooking($this->conversation, $other, '2026-07-21 11:00', AiBookingStatus::CONFIRMED);
        $this->makePendingBooking($this->makeConversation($this->tenant, $this->owner), $other, '2026-07-21 10:00');
        $this->mockGoogle([]);

        $this->putJson("/api/tasks/{$task->id}", $this->interval('2026-07-21 10:00'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'overlap_confirmation_required');
        $this->putJson("/api/tasks/{$task->id}", $this->interval('2026-07-21 09:00'))->assertOk();

        $this->assertTrue($task->fresh()->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 09:00', self::TZ)));
    }

    public function test_changing_meeting_status_withdraws_pending_reschedule_request(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner, '2026-07-21 11:00', AiBookingStatus::CONFIRMED);
        Task::withoutGlobalScopes()->whereKey($task->id)->update([
            'ai_reschedule_starts_at' => '2026-07-22 10:00:00',
            'ai_reschedule_ends_at' => '2026-07-22 10:30:00',
            'ai_reschedule_requested_at' => now(),
        ]);

        $this->putJson("/api/tasks/{$task->id}", ['status' => TaskStatus::DONE->value])->assertOk();

        $task->refresh();
        $this->assertSame(TaskStatus::DONE, $task->status);
        $this->assertNull($task->ai_reschedule_starts_at);
        $this->assertNull($task->ai_reschedule_ends_at);
        $this->assertNull($task->ai_reschedule_requested_at);
    }

    public function test_held_owner_lock_refuses_the_move_without_reading_google(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner, '2026-07-21 11:00', AiBookingStatus::CONFIRMED);
        $this->mockGoogle([]);
        Sleep::fake(syncWithCarbon: true);
        $held = Cache::lock("ai-booking:owner:{$this->owner->id}", 60);
        $this->assertTrue($held->get());

        $this->putJson("/api/tasks/{$task->id}", $this->interval('2026-07-21 10:00'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'calendar_busy');

        $held->release();
        $this->assertTrue($task->fresh()->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 11:00', self::TZ)));
    }

    public function test_editing_other_fields_does_not_check_the_agenda(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner, '2026-07-21 11:00', AiBookingStatus::CONFIRMED);
        $this->mockGoogle([]);

        $this->putJson("/api/tasks/{$task->id}", ['name' => 'Reunión renombrada', ...$this->interval('2026-07-21 11:00')])->assertOk();

        $this->assertSame('Reunión renombrada', $task->fresh()->name);
    }

    /** @return array{starts_at: string, ends_at: string} */
    private function interval(string $start): array
    {
        $startsAt = CarbonImmutable::parse($start, self::TZ);

        return ['starts_at' => $startsAt->toIso8601String(), 'ends_at' => $startsAt->addMinutes(30)->toIso8601String()];
    }
}
