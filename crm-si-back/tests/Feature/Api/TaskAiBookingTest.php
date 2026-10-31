<?php

namespace Tests\Feature\Api;

use App\Enums\AiBookingStatus;
use App\Enums\TaskStatus;
use App\Jobs\SyncTaskCalendarEventJob;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Scheduling\AppointmentBookingException;
use App\Services\Scheduling\AppointmentBookingService;
use App\Services\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
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

    private function expectContactMessage(callable $matches): void
    {
        $this->mock(WhatsAppMessageService::class, fn ($mock) => $mock
            ->shouldReceive('sendSystemTextMessageFromCRM')->once()
            ->withArgs(fn (Conversation $conversation, string $text) => $conversation->id === $this->conversation->id && $matches($text)));
    }
}
