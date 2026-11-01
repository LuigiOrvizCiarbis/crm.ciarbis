<?php

namespace Tests\Feature\Api;

use App\Enums\AiBookingStatus;
use App\Enums\TaskStatus;
use App\Jobs\SyncTaskCalendarEventJob;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Scheduling\AppointmentAvailabilityService;
use App\Services\Scheduling\AppointmentBookingException;
use App\Services\Scheduling\AppointmentBookingService;
use App\Services\WhatsAppMessageService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\AiSchedulingFixtures;
use Tests\TestCase;

class TaskAiBookingTest extends TestCase
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
        $this->resetSchedulingFakes();

        parent::tearDown();
    }

    public function test_approve_confirms_queues_google_sync_and_notifies_contact(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner);
        $this->mockGoogle([$this->googleEvents()]);
        $this->expectContactMessage(fn (string $text) => str_contains($text, 'quedó confirmado para el martes 21 de julio a las 11:00'));

        $response = $this->postJson("/api/tasks/{$task->id}/ai-booking/approve");

        $response->assertOk()
            ->assertJsonPath('data.ai_booking_status', 'confirmed')
            ->assertJsonPath('data.ai_booking_decided_by', $this->owner->id)
            ->assertJsonPath('contact_notified', true);
        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->taskId === $task->id && $job->action === 'upsert');
    }

    public function test_approve_still_applies_when_contact_cannot_be_notified(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner);
        $this->mockGoogle([$this->googleEvents()]);
        $this->mock(WhatsAppMessageService::class, fn ($mock) => $mock
            ->shouldReceive('sendSystemTextMessageFromCRM')->once()
            ->andThrow(new \RuntimeException('Fuera de la ventana de 24 h')));

        $this->postJson("/api/tasks/{$task->id}/ai-booking/approve")
            ->assertOk()
            ->assertJsonPath('data.ai_booking_status', 'confirmed')
            ->assertJsonPath('contact_notified', false);
    }

    public function test_approve_is_refused_when_slot_was_taken_in_google(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner);
        $this->mockGoogle([$this->googleEvents([$this->googleEvent('2026-07-21 11:00', '2026-07-21 12:00')])]);

        $this->postJson("/api/tasks/{$task->id}/ai-booking/approve")
            ->assertStatus(409)
            ->assertJsonPath('message', 'El horario ya está ocupado en la agenda del responsable');

        $this->assertSame(AiBookingStatus::PENDING, $task->fresh()->ai_booking_status);
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
    }

    public function test_approve_requires_connected_calendar_of_assignee(): void
    {
        $other = $this->makeUser($this->tenant);
        $task = $this->makePendingBooking($this->conversation, $other);

        $this->postJson("/api/tasks/{$task->id}/ai-booking/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'El responsable no tiene Google Calendar conectado');

        $this->assertSame(AiBookingStatus::PENDING, $task->fresh()->ai_booking_status);
    }

    public function test_reject_cancels_and_notifies_without_google_sync(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner);
        $this->expectContactMessage(fn (string $text) => str_contains($text, 'no podemos confirmar el turno'));

        $this->postJson("/api/tasks/{$task->id}/ai-booking/reject")
            ->assertOk()
            ->assertJsonPath('data.ai_booking_status', 'rejected')
            ->assertJsonPath('data.status', 'cancelado')
            ->assertJsonPath('contact_notified', true);

        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
    }

    /**
     * Aceptar en el chat y Rechazar en Tareas al mismo tiempo: la aprobación
     * que leyó la tarea todavía pendiente pierde la carrera y no encola el
     * evento ni avisa al cliente.
     */
    public function test_approval_that_loses_race_against_rejection_does_nothing(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner);
        $stale = Task::withoutGlobalScopes()->find($task->id);
        $this->mockGoogle([$this->googleEvents()]);
        $this->mock(WhatsAppMessageService::class, fn ($mock) => $mock->shouldNotReceive('sendSystemTextMessageFromCRM'));

        // El rechazo se aplica entre la lectura y la escritura de la aprobación.
        Task::withoutGlobalScopes()->whereKey($task->id)->update([
            'ai_booking_status' => AiBookingStatus::REJECTED->value,
            'status' => TaskStatus::CANCELED->value,
        ]);

        try {
            app(AppointmentBookingService::class)->approve($stale, $this->owner);
            $this->fail('La aprobación tendría que haber perdido la carrera');
        } catch (AppointmentBookingException $e) {
            $this->assertSame(AppointmentBookingException::ALREADY_DECIDED, $e->reason);
        }

        $this->assertSame(AiBookingStatus::REJECTED, $task->fresh()->ai_booking_status);
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
    }

    #[DataProvider('concurrentAgendaEdits')]
    public function test_approval_refuses_a_concurrently_edited_agenda(string $field): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner);
        $replacement = $this->makeUser($this->tenant);
        $changes = match ($field) {
            'assigned_to' => ['assigned_to' => $replacement->id],
            'starts_at' => ['starts_at' => $task->starts_at->copy()->addHour()],
            'ends_at' => ['ends_at' => $task->ends_at->copy()->addHour()],
        };
        $this->mock(WhatsAppMessageService::class, fn ($mock) => $mock->shouldNotReceive('sendSystemTextMessageFromCRM'));
        $this->mock(AppointmentAvailabilityService::class, fn ($mock) => $mock
            ->shouldReceive('hasConflict')->once()
            ->andReturnUsing(function () use ($task, $changes) {
                // La edición se confirma mientras Google valida los valores anteriores.
                Task::withoutGlobalScopes()->whereKey($task->id)->update($changes);

                return false;
            }));

        $this->postJson("/api/tasks/{$task->id}/ai-booking/approve")->assertStatus(409);

        $this->assertSame(AiBookingStatus::PENDING, $task->fresh()->ai_booking_status);
        $this->assertNull($task->fresh()->ai_booking_decided_at);
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
    }

    public static function concurrentAgendaEdits(): array
    {
        return [
            'assignee changed' => ['assigned_to'],
            'start changed' => ['starts_at'],
            'end changed' => ['ends_at'],
        ];
    }

    public function test_decided_booking_cannot_be_decided_again(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner, status: AiBookingStatus::CONFIRMED);

        $this->postJson("/api/tasks/{$task->id}/ai-booking/approve")->assertStatus(409);
        $this->postJson("/api/tasks/{$task->id}/ai-booking/reject")->assertStatus(409);
    }

    /**
     * Cancelar la propuesta desde la edición normal de tareas la saca de la
     * cola de aprobación: ya no se puede aceptar ni aparece como pendiente.
     */
    public function test_cancelling_pending_booking_via_task_update_resolves_it(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner);

        $this->putJson("/api/tasks/{$task->id}", ['status' => 'cancelado'])->assertOk();

        $task->refresh();
        $this->assertSame(AiBookingStatus::REJECTED, $task->ai_booking_status);
        $this->assertSame($this->owner->id, $task->ai_booking_decided_by);
        $this->postJson("/api/tasks/{$task->id}/ai-booking/approve")->assertStatus(409);
        $this->getJson("/api/tasks?conversation_id={$this->conversation->id}&ai_booking_status=pending")
            ->assertJsonCount(0, 'data');
    }

    public function test_changing_pending_booking_to_other_type_clears_it(): void
    {
        $task = $this->makePendingBooking($this->conversation, $this->owner);

        $this->putJson("/api/tasks/{$task->id}", ['type' => 'llamado'])->assertOk();

        $this->assertNull($task->fresh()->ai_booking_status);
        $this->postJson("/api/tasks/{$task->id}/ai-booking/approve")->assertStatus(409);
    }

    public function test_tasks_can_be_filtered_by_conversation_and_booking_status(): void
    {
        $pending = $this->makePendingBooking($this->conversation, $this->owner);
        $this->makePendingBooking($this->conversation, $this->owner, '2026-07-22 11:00', AiBookingStatus::CONFIRMED);
        $otherConversation = $this->makeConversation($this->tenant, $this->owner);
        $this->makePendingBooking($otherConversation, $this->owner, '2026-07-23 11:00');

        $this->getJson("/api/tasks?conversation_id={$this->conversation->id}&ai_booking_status=pending")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);
    }

    public function test_booking_of_other_tenant_is_not_reachable(): void
    {
        [$otherTenant, $otherOwner] = $this->makeTenantWithOwner();
        $foreign = $this->makePendingBooking($this->makeConversation($otherTenant, $otherOwner), $otherOwner);

        $this->postJson("/api/tasks/{$foreign->id}/ai-booking/approve")->assertNotFound();
        $this->assertSame(AiBookingStatus::PENDING, $foreign->fresh()->ai_booking_status);
    }

    public function test_approving_a_reschedule_moves_the_turno_syncs_google_and_notifies_contact(): void
    {
        $task = $this->requestedReschedule('2026-07-21 11:00', '2026-07-22 10:00');
        $this->mockGoogle([$this->googleEvents()]);
        $this->expectContactMessage(fn (string $text) => $text === '¡Listo! Tu turno se cambió al miércoles 22 de julio a las 10:00 hs.');

        $this->postJson("/api/tasks/{$task->id}/ai-booking/reschedule/approve")
            ->assertOk()
            ->assertJsonPath('data.ai_booking_status', 'confirmed')
            ->assertJsonPath('data.ai_reschedule_starts_at', null)
            ->assertJsonPath('data.ai_booking_decided_by', $this->owner->id)
            ->assertJsonPath('contact_notified', true);

        $task->refresh();
        $this->assertTrue($task->starts_at->equalTo(CarbonImmutable::parse('2026-07-22 10:00', self::TZ)));
        $this->assertTrue($task->ends_at->equalTo(CarbonImmutable::parse('2026-07-22 10:30', self::TZ)));
        $this->assertTrue($task->deadline->equalTo($task->starts_at));
        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->taskId === $task->id && $job->action === 'upsert');
    }

    public function test_approving_a_reschedule_ignores_the_turnos_own_google_event(): void
    {
        $task = $this->requestedReschedule('2026-07-21 11:00', '2026-07-21 11:15');
        $this->mockGoogle([$this->googleEvents([$this->googleEvent('2026-07-21 11:00', '2026-07-21 11:30', [
            'extendedProperties' => ['private' => ['crm_task_id' => (string) $task->id, 'crm_tenant_id' => (string) $this->tenant->id]],
        ])])]);
        $this->expectContactMessage(fn () => true);

        $this->postJson("/api/tasks/{$task->id}/ai-booking/reschedule/approve")->assertOk();

        $this->assertTrue($task->fresh()->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 11:15', self::TZ)));
    }

    public function test_reschedule_approval_is_refused_on_conflict_or_past_proposal(): void
    {
        $task = $this->requestedReschedule('2026-07-21 11:00', '2026-07-22 10:00');
        $this->mockGoogle([$this->googleEvents([$this->googleEvent('2026-07-22 10:00', '2026-07-22 11:00')])]);

        $this->postJson("/api/tasks/{$task->id}/ai-booking/reschedule/approve")
            ->assertStatus(409)
            ->assertJsonPath('message', 'El horario ya está ocupado en la agenda del responsable');
        $this->assertNotNull($task->fresh()->ai_reschedule_starts_at);

        $past = $this->requestedReschedule('2026-07-23 11:00', '2026-07-20 07:00', $this->makeConversation($this->tenant, $this->owner, $this->owner->id));
        $this->postJson("/api/tasks/{$past->id}/ai-booking/reschedule/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'El horario propuesto ya pasó');

        $this->assertTrue($task->fresh()->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 11:00', self::TZ)));
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
    }

    public function test_reschedule_approval_refuses_a_request_that_changed_meanwhile(): void
    {
        $task = $this->requestedReschedule('2026-07-21 11:00', '2026-07-22 10:00');
        $stale = $task->fresh();
        $this->setRequest($task, '2026-07-22 11:00');
        $this->mockGoogle([$this->googleEvents()]);

        try {
            app(AppointmentBookingService::class)->approveReschedule($stale, $this->owner);
            $this->fail('A changed request must not be applied.');
        } catch (AppointmentBookingException $e) {
            $this->assertSame(AppointmentBookingException::ALREADY_DECIDED, $e->reason);
        }

        $task->refresh();
        $this->assertTrue($task->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 11:00', self::TZ)));
        $this->assertTrue($task->ai_reschedule_starts_at->equalTo(CarbonImmutable::parse('2026-07-22 11:00', self::TZ)));
    }

    public function test_rejecting_a_reschedule_keeps_the_turno_and_tells_the_contact_both_times(): void
    {
        $task = $this->requestedReschedule('2026-07-21 11:00', '2026-07-22 10:00');
        $this->expectContactMessage(fn (string $text) => $text === 'No pudimos cambiar tu turno al miércoles 22 de julio a las 10:00 hs. '
            .'Seguís con tu turno del martes 21 de julio a las 11:00 hs. ¿Querés que busquemos otro horario?');

        $this->postJson("/api/tasks/{$task->id}/ai-booking/reschedule/reject")
            ->assertOk()
            ->assertJsonPath('contact_notified', true);

        $task->refresh();
        $this->assertNull($task->ai_reschedule_starts_at);
        $this->assertNull($task->ai_reschedule_ends_at);
        $this->assertSame(AiBookingStatus::CONFIRMED, $task->ai_booking_status);
        $this->assertTrue($task->starts_at->equalTo(CarbonImmutable::parse('2026-07-21 11:00', self::TZ)));
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
    }

    public function test_reschedule_decisions_require_a_pending_request(): void
    {
        $confirmed = $this->makePendingBooking($this->conversation, $this->owner, status: AiBookingStatus::CONFIRMED);
        $pending = $this->makePendingBooking($this->makeConversation($this->tenant, $this->owner, $this->owner->id), $this->owner, '2026-07-22 11:00');

        foreach ([$confirmed, $pending] as $task) {
            foreach (['approve', 'reject'] as $action) {
                $this->postJson("/api/tasks/{$task->id}/ai-booking/reschedule/{$action}")
                    ->assertStatus(409)
                    ->assertJsonPath('message', 'El cambio de turno ya fue resuelto');
            }
        }
    }

    public function test_tasks_can_be_filtered_by_pending_reschedule(): void
    {
        $requested = $this->requestedReschedule('2026-07-21 11:00', '2026-07-22 10:00');
        $this->makePendingBooking($this->makeConversation($this->tenant, $this->owner, $this->owner->id), $this->owner, '2026-07-23 11:00', AiBookingStatus::CONFIRMED);

        $this->getJson('/api/tasks?ai_reschedule_pending=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requested->id);
    }

    private function requestedReschedule(string $start, string $proposed, ?Conversation $conversation = null): Task
    {
        $task = $this->makePendingBooking($conversation ?? $this->conversation, $this->owner, $start, AiBookingStatus::CONFIRMED);
        $this->setRequest($task, $proposed);

        return $task->fresh();
    }

    private function setRequest(Task $task, string $start): void
    {
        $startsAt = CarbonImmutable::parse($start, self::TZ)->setTimezone(config('app.timezone'));
        Task::withoutGlobalScopes()->whereKey($task->id)->update([
            'ai_reschedule_starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ai_reschedule_ends_at' => $startsAt->addMinutes(30)->format('Y-m-d H:i:s'),
            'ai_reschedule_requested_at' => now(),
        ]);
    }

    private function expectContactMessage(callable $matches): void
    {
        $this->mock(WhatsAppMessageService::class, fn ($mock) => $mock
            ->shouldReceive('sendSystemTextMessageFromCRM')->once()
            ->withArgs(fn (Conversation $conversation, string $text) => $conversation->id === $this->conversation->id && $matches($text)));
    }
}
