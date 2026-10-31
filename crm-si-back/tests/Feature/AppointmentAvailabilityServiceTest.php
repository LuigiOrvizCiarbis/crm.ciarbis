<?php

namespace Tests\Feature;

use App\Models\AiSchedulingConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Scheduling\AppointmentAvailabilityException;
use App\Services\Scheduling\AppointmentAvailabilityService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\AiSchedulingFixtures;
use Tests\TestCase;

class AppointmentAvailabilityServiceTest extends TestCase
{
    use AiSchedulingFixtures, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private AiSchedulingConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        [$this->tenant, $this->owner] = $this->makeTenantWithOwner();
        $this->connectGoogle($this->owner);
        $this->config = $this->makeSchedulingConfig($this->tenant);
    }

    protected function tearDown(): void
    {
        $this->resetSchedulingFakes();

        parent::tearDown();
    }

    public function test_min_notice_and_google_events_remove_slots(): void
    {
        $this->mockGoogle([$this->googleEvents([
            $this->googleEvent('2026-07-20 10:00', '2026-07-20 11:00'),
        ])]);

        // now = 08:00 + 2 h de anticipación → desde las 10:00; 10:00 y 10:30 chocan con el evento.
        $this->assertSame(['11:00', '11:30'], $this->mondaySlots());
    }

    public function test_transparent_and_declined_events_do_not_block(): void
    {
        $this->mockGoogle([$this->googleEvents([
            $this->googleEvent('2026-07-20 10:00', '2026-07-20 11:00', ['transparency' => 'transparent']),
            $this->googleEvent('2026-07-20 11:00', '2026-07-20 12:00', [
                'attendees' => [['email' => 'owner@example.com', 'self' => true, 'responseStatus' => 'declined']],
            ]),
        ])]);

        $this->assertSame(['10:00', '10:30', '11:00', '11:30'], $this->mondaySlots());
    }

    public function test_all_day_event_blocks_the_whole_day(): void
    {
        $this->mockGoogle([$this->googleEvents([
            ['status' => 'confirmed', 'start' => ['date' => '2026-07-20'], 'end' => ['date' => '2026-07-21']],
        ])]);

        $this->assertSame([], $this->mondaySlots());
    }

    public function test_all_day_event_uses_calendar_timezone_for_slots_and_approval(): void
    {
        $this->config->update([
            'working_hours' => ['mon' => [['start' => '10:00', 'end' => '14:00']]],
        ]);
        $response = new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'timeZone' => 'Asia/Tokyo',
            'items' => [
                ['status' => 'confirmed', 'start' => ['date' => '2026-07-21'], 'end' => ['date' => '2026-07-22']],
            ],
        ]));
        $this->mockGoogle([$response, $response]);

        // El día 21 en Tokio empieza el día 20 a las 12:00 en Buenos Aires.
        $this->assertSame(['10:00', '10:30', '11:00', '11:30'], $this->mondaySlots());
        $start = CarbonImmutable::parse('2026-07-20 12:00', self::TZ);
        $this->assertTrue(app(AppointmentAvailabilityService::class)->hasConflict($this->owner, $start, $start->addMinutes(30)));
    }

    public function test_crm_meeting_pending_approval_blocks_its_slot(): void
    {
        $this->mockGoogle([$this->googleEvents()]);
        $conversation = $this->makeConversation($this->tenant, $this->owner);
        $this->makePendingBooking($conversation, $this->owner, '2026-07-20 11:00');

        $this->assertSame(['10:00', '10:30', '11:30'], $this->mondaySlots());
    }

    /**
     * Las columnas de tasks guardan la hora local de la app (Buenos Aires).
     * Con la agenda en otra zona, la consulta de solapamiento tiene que
     * convertir los límites o deja de ver la reunión pendiente.
     */
    public function test_pending_meeting_blocks_slot_when_agenda_uses_other_timezone(): void
    {
        $this->config->update([
            'timezone' => 'UTC',
            'min_notice_hours' => 0,
            'working_hours' => [...AiSchedulingConfig::DEFAULT_WORKING_HOURS, 'mon' => [['start' => '13:00', 'end' => '15:00']]],
        ]);
        $this->mockGoogle([$this->googleEvents()]);
        $conversation = $this->makeConversation($this->tenant, $this->owner);
        // 10:00 en Buenos Aires = 13:00 UTC.
        $this->makePendingBooking($conversation, $this->owner, '2026-07-20 10:00');

        $from = CarbonImmutable::parse('2026-07-20 00:00', 'UTC');
        $slots = app(AppointmentAvailabilityService::class)->availableSlots($this->config->fresh(), $this->owner, $from, $from->addDay());

        $this->assertSame(['13:30', '14:00', '14:30'], array_map(fn ($s) => $s->format('H:i'), $slots));
    }

    public function test_google_error_raises_availability_exception(): void
    {
        $this->mockGoogle([new Response(500, ['Content-Type' => 'application/json'], '{"error":{"code":500}}')]);

        $this->expectException(AppointmentAvailabilityException::class);

        $this->mondaySlots();
    }

    public function test_has_conflict_ignores_the_proposal_being_approved(): void
    {
        $conversation = $this->makeConversation($this->tenant, $this->owner);
        $task = $this->makePendingBooking($conversation, $this->owner, '2026-07-21 11:00');
        $start = CarbonImmutable::parse('2026-07-21 11:00', self::TZ);
        $service = app(AppointmentAvailabilityService::class);

        $this->mockGoogle([
            $this->googleEvents(),
            $this->googleEvents([$this->googleEvent('2026-07-21 11:15', '2026-07-21 12:00')]),
        ]);

        $this->assertFalse($service->hasConflict($this->owner, $start, $start->addMinutes(30), $task->id));
        $this->assertTrue($service->hasConflict($this->owner, $start, $start->addMinutes(30), $task->id));
    }

    /**
     * @return list<string>
     */
    private function mondaySlots(): array
    {
        $monday = CarbonImmutable::parse('2026-07-20 00:00', self::TZ);
        $slots = app(AppointmentAvailabilityService::class)
            ->availableSlots($this->config->fresh(), $this->owner, $monday, $monday->addDay());

        return array_map(fn (CarbonImmutable $slot) => $slot->format('H:i'), $slots);
    }
}
