<?php

namespace Tests\Feature;

use App\Enums\AiBookingStatus;
use App\Jobs\SyncTaskCalendarEventJob;
use App\Models\AiSchedulingConfig;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiTool;
use App\Services\Scheduling\AppointmentAvailabilityService;
use App\Services\Scheduling\AppointmentBookingService;
use App\Services\Scheduling\AppointmentToolset;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\AiSchedulingFixtures;
use Tests\TestCase;

class AppointmentToolsetTest extends TestCase
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

    public function test_approval_mode_creates_pending_meeting_without_google_sync(): void
    {
        $this->mockGoogle([$this->googleEvents()]);
        $toolset = $this->toolset(['mode' => 'approval']);

        $result = $toolset->call(AppointmentToolset::BOOK, ['inicio' => '2026-07-20 11:00', 'motivo' => 'Cotización']);

        $this->assertStringContainsString('PENDIENTE', $result);
        $task = Task::withoutGlobalScopes()->sole();
        $this->assertSame(AiBookingStatus::PENDING, $task->ai_booking_status);
        $this->assertSame('reunion', $task->type->value);
        $this->assertSame($this->owner->id, $task->assigned_to);
        $this->assertSame($this->conversation->id, $task->conversation_id);
        $this->assertTrue($task->starts_at->equalTo(CarbonImmutable::parse('2026-07-20 11:00', self::TZ)));
        $this->assertTrue($task->ends_at->equalTo(CarbonImmutable::parse('2026-07-20 11:30', self::TZ)));
        $this->assertStringContainsString('Motivo: Cotización', $task->description);
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
        $this->assertStringStartsWith('Registramos tu pedido de turno para el lunes 20 de julio a las 11:00', $toolset->bookingFallbackMessage());
    }

    public function test_auto_mode_confirms_and_queues_google_sync_without_promising_invitation(): void
    {
        $this->mockGoogle([$this->googleEvents()]);
        $toolset = $this->toolset(['mode' => 'auto']);

        $result = $toolset->call(AppointmentToolset::BOOK, ['inicio' => '2026-07-20 11:00', 'email' => 'jane@example.com']);

        $this->assertStringContainsString('CONFIRMADO', $result);
        $this->assertStringNotContainsString('invitación', $result);
        $task = Task::withoutGlobalScopes()->sole();
        $this->assertSame(AiBookingStatus::CONFIRMED, $task->ai_booking_status);
        $this->assertSame('jane@example.com', $task->meeting_guest_email);
        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->taskId === $task->id && $job->action === 'upsert');
    }

    public function test_criteria_mode_follows_the_model_decision(): void
    {
        $this->mockGoogle([$this->googleEvents()]);
        $this->toolset(['mode' => 'criteria', 'criteria' => 'Directo si dio presupuesto'])
            ->call(AppointmentToolset::BOOK, ['inicio' => '2026-07-20 11:00', 'agendar_directo' => false]);

        $this->assertSame(AiBookingStatus::PENDING, Task::withoutGlobalScopes()->sole()->ai_booking_status);

        Task::withoutGlobalScopes()->delete();
        $this->mockGoogle([$this->googleEvents()]);
        AiSchedulingConfig::withoutGlobalScopes()->delete();
        $this->toolset(['mode' => 'criteria', 'criteria' => 'Directo si dio presupuesto'])
            ->call(AppointmentToolset::BOOK, ['inicio' => '2026-07-20 11:00', 'agendar_directo' => true]);

        $this->assertSame(AiBookingStatus::CONFIRMED, Task::withoutGlobalScopes()->sole()->ai_booking_status);
    }

    public function test_second_booking_in_same_conversation_is_refused(): void
    {
        $this->mockGoogle([$this->googleEvents()]);
        $toolset = $this->toolset();
        $toolset->call(AppointmentToolset::BOOK, ['inicio' => '2026-07-20 11:00']);

        $result = $toolset->call(AppointmentToolset::BOOK, ['inicio' => '2026-07-20 11:30']);

        $this->assertStringContainsString('ya tiene un turno pendiente', $result);
        $this->assertSame(1, Task::withoutGlobalScopes()->count());
    }

    public function test_busy_slot_is_refused(): void
    {
        $this->mockGoogle([$this->googleEvents([$this->googleEvent('2026-07-20 11:00', '2026-07-20 12:00')])]);

        $result = $this->toolset()->call(AppointmentToolset::BOOK, ['inicio' => '2026-07-20 11:00']);

        $this->assertStringContainsString('no está disponible', $result);
        $this->assertSame(0, Task::withoutGlobalScopes()->count());
    }

    public function test_handoff_before_booking_creates_nothing(): void
    {
        $this->mockGoogle([$this->googleEvents()]);
        $toolset = $this->toolset(['mode' => 'auto']);
        // Un vendedor respondió mientras el modelo generaba.
        Conversation::withoutGlobalScopes()->whereKey($this->conversation->id)->update(['ai_autoreply_enabled' => false]);

        $result = $toolset->call(AppointmentToolset::BOOK, ['inicio' => '2026-07-20 11:00']);

        $this->assertStringContainsString('Un asesor tomó la conversación', $result);
        $this->assertSame(0, Task::withoutGlobalScopes()->count());
        $this->assertNull($toolset->bookingFallbackMessage());
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
    }

    public function test_invalid_or_impossible_start_is_rejected(): void
    {
        $toolset = $this->toolset();

        foreach (['2026-02-30 11:00', 'mañana 11', '2026-07-20 11:00:00'] as $input) {
            $this->assertSame(
                'Formato de inicio inválido. Usá YYYY-MM-DD HH:MM.',
                $toolset->call(AppointmentToolset::BOOK, ['inicio' => $input]),
                $input,
            );
        }

        $this->assertSame(0, Task::withoutGlobalScopes()->count());
    }

    public function test_availability_lists_slots_by_day_and_contact_email_status(): void
    {
        $this->mockGoogle([$this->googleEvents([$this->googleEvent('2026-07-20 10:00', '2026-07-20 11:00')])]);

        $result = $this->toolset()->call(AppointmentToolset::CHECK_AVAILABILITY, []);

        $this->assertStringContainsString('- lunes 20/07/2026: 11:00, 11:30', $result);
        $this->assertStringContainsString('- martes 21/07/2026: 09:00, 09:30, 10:00, 10:30, 11:00, 11:30', $result);
        $this->assertStringContainsString('El cliente no tiene email registrado', $result);
    }

    public function test_agendar_directo_parameter_exists_only_in_criteria_mode(): void
    {
        $this->assertSame(['inicio'], $this->bookSchema($this->toolset(['mode' => 'approval']))['required']);

        AiSchedulingConfig::withoutGlobalScopes()->delete();
        $schema = $this->bookSchema($this->toolset(['mode' => 'criteria', 'criteria' => 'x']));

        $this->assertSame(['inicio', 'agendar_directo'], $schema['required']);
        $this->assertArrayHasKey('agendar_directo', $schema['properties']);
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

    /**
     * @return array<string, mixed>
     */
    private function bookSchema(AppointmentToolset $toolset): array
    {
        $tool = collect($toolset->definitions())->first(fn (AiTool $t) => $t->name === AppointmentToolset::BOOK);

        return $tool->parameters;
    }
}
