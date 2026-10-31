<?php

namespace Tests\Feature;

use App\Enums\AiBookingStatus;
use App\Jobs\SyncTaskCalendarEventJob;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Scheduling\AppointmentAvailabilityService;
use App\Services\Scheduling\AppointmentBookingService;
use App\Services\Scheduling\AppointmentToolset;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\AiSchedulingFixtures;
use Tests\TestCase;

/**
 * reprogramar_turno: el agente mueve el turno vigente de la conversación
 * respetando el modo de la agenda. "Ahora" es el lunes 20/07/2026 08:00 BA.
 */
class AppointmentRescheduleTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $this->resetSchedulingFakes();

        parent::tearDown();
    }

    public function test_auto_mode_moves_confirmed_turno_onto_an_overlapping_slot_and_syncs_google(): void
    {
        $task = $this->booking('2026-07-21 10:00', minutes: 60);
        $this->mockGoogle([$this->googleEvents([$this->ownEvent($task, '2026-07-21 10:00', '2026-07-21 11:00')])]);
        $toolset = $this->toolset(['mode' => 'auto', 'meeting_duration_minutes' => 60]);

        $result = $toolset->call(AppointmentToolset::RESCHEDULE, ['inicio' => '2026-07-21 10:30']);

        $this->assertStringContainsString('CAMBIADO', $result);
        $task->refresh();
        $this->assertSame(AiBookingStatus::CONFIRMED, $task->ai_booking_status);
        $this->assertTrue($task->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 10:30', self::TZ)));
        $this->assertTrue($task->ends_at->equalTo(CarbonImmutable::parse('2026-07-21 11:30', self::TZ)));
        $this->assertTrue($task->deadline->equalTo($task->starts_at));
        $this->assertNull($task->ai_reschedule_starts_at);
        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->taskId === $task->id && $job->action === 'upsert');
        $this->assertSame('Tu turno quedó cambiado para el martes 21 de julio a las 10:30 hs.', $toolset->bookingFallbackMessage());
    }

    public function test_approval_mode_keeps_confirmed_turno_and_records_a_request_that_blocks_the_slot(): void
    {
        $task = $this->booking('2026-07-21 11:00');
        $this->mockGoogle([$this->googleEvents(), $this->googleEvents()]);
        $toolset = $this->toolset(['mode' => 'approval']);

        $result = $toolset->call(AppointmentToolset::RESCHEDULE, ['inicio' => '2026-07-21 10:00']);

        $this->assertStringContainsString('pedido de cambio al martes 21 de julio a las 10:00', $result);
        $this->assertStringContainsString('sigue vigente el turno del martes 21 de julio a las 11:00', $result);
        $task->refresh();
        $this->assertTrue($task->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 11:00', self::TZ)));
        $this->assertTrue($task->ai_reschedule_starts_at->equalTo(CarbonImmutable::parse('2026-07-21 10:00', self::TZ)));
        $this->assertTrue($task->ai_reschedule_ends_at->equalTo(CarbonImmutable::parse('2026-07-21 10:30', self::TZ)));
        $this->assertNotNull($task->ai_reschedule_requested_at);
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
        $this->assertStringStartsWith('Registramos tu pedido para cambiar el turno al martes 21 de julio a las 10:00 hs.', $toolset->bookingFallbackMessage());

        // Otra conversación del mismo vendedor ya no ve el horario pedido ni el vigente.
        $other = $this->toolsetFor($this->makeConversation($this->tenant, $this->owner, $this->owner->id), $toolset);
        $availability = $other->call(AppointmentToolset::CHECK_AVAILABILITY, ['desde' => '2026-07-21']);
        $this->assertStringContainsString('martes 21/07/2026: 09:00, 09:30, 10:30, 11:30', $availability);
    }

    public function test_criteria_mode_follows_the_model_decision(): void
    {
        $task = $this->booking('2026-07-21 11:00');
        $this->mockGoogle([$this->googleEvents(), $this->googleEvents()]);
        $toolset = $this->toolset(['mode' => 'criteria', 'criteria' => 'Directo si es el mismo día']);

        $toolset->call(AppointmentToolset::RESCHEDULE, ['inicio' => '2026-07-21 10:00', 'agendar_directo' => false]);
        $this->assertNotNull($task->fresh()->ai_reschedule_starts_at);
        $this->assertTrue($task->fresh()->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 11:00', self::TZ)));

        $toolset->call(AppointmentToolset::RESCHEDULE, ['inicio' => '2026-07-21 09:30', 'agendar_directo' => true]);
        $task->refresh();
        $this->assertTrue($task->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 09:30', self::TZ)));
        $this->assertNull($task->ai_reschedule_starts_at, 'A direct move drops the previous request.');
        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->taskId === $task->id && $job->action === 'upsert');
    }

    public function test_pending_turno_moves_and_stays_pending_even_in_auto_mode(): void
    {
        $task = $this->booking('2026-07-21 11:00', AiBookingStatus::PENDING);
        $this->mockGoogle([$this->googleEvents()]);
        $toolset = $this->toolset(['mode' => 'auto']);

        $result = $toolset->call(AppointmentToolset::RESCHEDULE, ['inicio' => '2026-07-22 09:00']);

        $this->assertStringContainsString('PENDIENTE', $result);
        $task->refresh();
        $this->assertSame(AiBookingStatus::PENDING, $task->ai_booking_status);
        $this->assertTrue($task->starts_at->equalTo(CarbonImmutable::parse('2026-07-22 09:00', self::TZ)));
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
        $this->assertStringStartsWith('Cambiamos tu pedido de turno al miércoles 22 de julio a las 09:00 hs.', $toolset->bookingFallbackMessage());
    }

    public function test_choosing_the_current_time_withdraws_the_pending_request_without_reading_google(): void
    {
        $task = $this->booking('2026-07-21 11:00');
        $this->setRequest($task, '2026-07-21 10:00');
        $this->mockGoogle([]);
        $toolset = $this->toolset(['mode' => 'approval']);

        $result = $toolset->call(AppointmentToolset::RESCHEDULE, ['inicio' => '2026-07-21 11:00']);

        $this->assertStringContainsString('no hubo cambios', $result);
        $this->assertNull($task->fresh()->ai_reschedule_starts_at);
        $this->assertNull($toolset->bookingFallbackMessage());
    }

    public function test_refusals_leave_the_turno_untouched(): void
    {
        $this->mockGoogle([]);
        $toolset = $this->toolset(['mode' => 'auto']);
        $this->assertStringContainsString('no tiene un turno vigente', $toolset->call(AppointmentToolset::RESCHEDULE, ['inicio' => '2026-07-21 10:00']));

        $task = $this->booking('2026-07-21 11:00');

        $this->mockGoogle([$this->googleEvents([$this->googleEvent('2026-07-21 10:00', '2026-07-21 10:30')])]);
        $this->assertStringContainsString('no está disponible', $toolset->call(AppointmentToolset::RESCHEDULE, ['inicio' => '2026-07-21 10:00']));

        Conversation::withoutGlobalScopes()->whereKey($this->conversation->id)->update(['ai_autoreply_enabled' => false]);
        $this->assertStringContainsString('Un asesor tomó la conversación', $toolset->call(AppointmentToolset::RESCHEDULE, ['inicio' => '2026-07-21 10:00']));
        Conversation::withoutGlobalScopes()->whereKey($this->conversation->id)->update(['ai_autoreply_enabled' => true]);

        // El turno quedó en el calendario de otro vendedor: no se mueve desde acá.
        $task->update(['assigned_to' => $this->makeUser($this->tenant)->id]);
        $this->assertStringContainsString('No puedo cambiar este turno', $toolset->call(AppointmentToolset::RESCHEDULE, ['inicio' => '2026-07-21 10:00']));

        $this->assertTrue($task->fresh()->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 11:00', self::TZ)));
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
    }

    public function test_availability_reports_the_current_turno_and_offers_slots_overlapping_it(): void
    {
        $task = $this->booking('2026-07-21 10:00', minutes: 60);
        $this->setRequest($task, '2026-07-22 09:00', minutes: 60);
        $this->mockGoogle([$this->googleEvents([$this->ownEvent($task, '2026-07-21 10:00', '2026-07-21 11:00')])]);
        $toolset = $this->toolset(['mode' => 'approval', 'meeting_duration_minutes' => 60]);

        $result = $toolset->call(AppointmentToolset::CHECK_AVAILABILITY, ['desde' => '2026-07-21']);

        $this->assertStringContainsString('martes 21/07/2026: 09:00, 09:30, 10:00, 10:30, 11:00', $result);
        $this->assertStringContainsString(
            'El cliente ya tiene un turno confirmado el martes 21 de julio a las 10:00; pidió cambiarlo al miércoles 22 de julio a las 09:00, pendiente de aprobación. Si quiere cambiarlo, usá reprogramar_turno.',
            $result,
        );
        $this->assertStringContainsString('usá reprogramar_turno', $toolset->call(AppointmentToolset::BOOK, ['inicio' => '2026-07-21 09:00']));
    }

    public function test_own_event_is_ignored_only_within_the_same_workspace(): void
    {
        $task = $this->booking('2026-07-21 10:00');
        $foreign = $this->googleEvent('2026-07-21 10:00', '2026-07-21 10:30', [
            'extendedProperties' => ['private' => ['crm_task_id' => (string) $task->id, 'crm_tenant_id' => (string) ($this->tenant->id + 1000)]],
        ]);
        $this->mockGoogle([$this->googleEvents([$foreign])]);
        $config = $this->makeSchedulingConfig($this->tenant);
        $day = CarbonImmutable::parse('2026-07-21', self::TZ);

        $slots = app(AppointmentAvailabilityService::class)->availableSlots($config, $this->owner, $day, $day->addDay(), ignoreTaskId: $task->id);

        $this->assertNotContains('10:00', array_map(fn (CarbonImmutable $slot) => $slot->format('H:i'), $slots));
    }

    private function booking(string $start, AiBookingStatus $status = AiBookingStatus::CONFIRMED, int $minutes = 30): Task
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner, $start, $status);
        $task->update(['ends_at' => CarbonImmutable::parse($start, self::TZ)->addMinutes($minutes)]);

        return $task->fresh();
    }

    private function setRequest(Task $task, string $start, int $minutes = 30): void
    {
        $startsAt = CarbonImmutable::parse($start, self::TZ)->setTimezone(config('app.timezone'));
        Task::withoutGlobalScopes()->whereKey($task->id)->update([
            'ai_reschedule_starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ai_reschedule_ends_at' => $startsAt->addMinutes($minutes)->format('Y-m-d H:i:s'),
            'ai_reschedule_requested_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function ownEvent(Task $task, string $start, string $end): array
    {
        return $this->googleEvent($start, $end, [
            'extendedProperties' => ['private' => ['crm_task_id' => (string) $task->id, 'crm_tenant_id' => (string) $this->tenant->id]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function toolset(array $overrides = []): AppointmentToolset
    {
        return new AppointmentToolset(
            $this->conversation,
            $this->makeSchedulingConfig($this->tenant, $overrides),
            $this->owner,
            app(AppointmentAvailabilityService::class),
            app(AppointmentBookingService::class),
        );
    }

    /** Otro toolset con la misma config, sobre $conversation. */
    private function toolsetFor(Conversation $conversation, AppointmentToolset $like): AppointmentToolset
    {
        $config = (new \ReflectionProperty($like, 'config'))->getValue($like);

        return new AppointmentToolset($conversation, $config, $this->owner, app(AppointmentAvailabilityService::class), app(AppointmentBookingService::class));
    }
}
