<?php

namespace Tests\Feature\Api;

use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Scheduling\AppointmentAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\AiSchedulingFixtures;
use Tests\Feature\Concerns\FakesGoogleCalendar;
use Tests\TestCase;

class GoogleCalendarEventTest extends TestCase
{
    use AiSchedulingFixtures, FakesGoogleCalendar, RefreshDatabase;

    private const RANGE = ['time_min' => '2026-07-20T00:00:00-03:00', 'time_max' => '2026-07-21T00:00:00-03:00'];

    private Tenant $tenant;

    private User $owner;

    private GoogleCalendarConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->freezeMonday();
        [$this->tenant, $this->owner] = $this->makeTenantWithOwner('Member');
        $this->connection = $this->connectGoogle($this->owner);
        Sanctum::actingAs($this->owner);
        $this->installGoogle();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        $this->resetSchedulingFakes();
        parent::tearDown();
    }

    public function test_lists_own_events_with_permissions_and_hides_noise(): void
    {
        $this->seedCalendar();

        $events = collect($this->getJson($this->listUrl())->assertOk()->json('data.events'))->keyBy('id');

        $this->assertSame(['event', true, true], [$events['own']['kind'], $events['own']['can_delete'], $events['own']['has_guests']]);
        $this->assertFalse($events['invite']['can_delete']);
        $this->assertSame(['availability_block', true], [$events['ownblock']['kind'], $events['ownblock']['can_delete']]);
        $this->assertSame(['availability_block', false], [$events['foreignblock']['kind'], $events['foreignblock']['can_delete']]);
        $this->assertSame(['crm_meeting', false], [$events['othermeeting']['kind'], $events['othermeeting']['can_delete']]);
        $this->assertFalse($events['birthday']['can_delete']);
        $this->assertSame([true, '2026-07-20', '2026-07-21'], [$events['allday']['all_day'], $events['allday']['start'], $events['allday']['end']]);
        $this->assertFalse($events['own']['recurring']);
        foreach (['cancelled', 'working', 'declined', 'mymeeting'] as $hidden) {
            $this->assertFalse($events->has($hidden), "{$hidden} must not be listed");
        }

        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame(self::RANGE['time_min'], $query['timeMin']);
        $this->assertSame(self::RANGE['time_max'], $query['timeMax']);
        $this->assertSame('true', $query['singleEvents']);
    }

    public function test_invalid_ranges_are_rejected_without_calling_google(): void
    {
        foreach ([
            ['time_min' => '2026-07-20', 'time_max' => self::RANGE['time_max']],
            ['time_min' => self::RANGE['time_max'], 'time_max' => self::RANGE['time_min']],
            ['time_min' => self::RANGE['time_min'], 'time_max' => '2026-10-22T00:00:00-03:00'],
        ] as $range) {
            $this->getJson('/api/google-calendar/events?'.http_build_query($range))->assertUnprocessable();
        }
        $this->assertCount(0, $this->transport);
    }

    public function test_notify_guests_only_sends_updates_when_requested_and_event_has_guests(): void
    {
        $this->seedCalendar();
        $this->events['own2'] = [...$this->events['own'], 'id' => 'own2'];
        $this->events['solo'] = $this->googleEvent('2026-07-20 18:00', '2026-07-20 19:00', ['id' => 'solo', 'organizer' => ['self' => true]]);

        foreach ([['own', '1', 'all'], ['own2', '0', 'none'], ['solo', '1', 'none']] as [$id, $notify, $sendUpdates]) {
            $this->installGoogle();
            $this->deleteJson("/api/google-calendar/events/{$id}?notify_guests={$notify}")->assertNoContent();
            $writes = $this->writes();
            $this->assertCount(1, $writes);
            $this->assertSame('DELETE', $writes[0]['request']->getMethod());
            parse_str($writes[0]['request']->getUri()->getQuery(), $query);
            $this->assertSame($sendUpdates, $query['sendUpdates'], $id);
            $this->assertArrayNotHasKey($id, $this->events);
        }
    }

    public function test_events_the_user_cannot_delete_are_rejected_and_kept(): void
    {
        $this->seedCalendar();

        foreach (['invite', 'ownblock', 'mymeeting', 'othermeeting', 'birthday'] as $id) {
            $this->deleteJson("/api/google-calendar/events/{$id}?notify_guests=1")
                ->assertStatus(409)->assertJsonPath('code', 'event_not_deletable');
            $this->assertArrayHasKey($id, $this->events);
        }
        $this->assertSame([], $this->writes());
    }

    public function test_deleting_a_missing_event_is_idempotent(): void
    {
        $this->deleteJson('/api/google-calendar/events/missing123')->assertNoContent();
        $this->assertSame([], $this->writes());
    }

    public function test_recurring_instance_deletes_only_that_occurrence(): void
    {
        foreach (['serie1_20260720T180000Z' => '2026-07-20', 'serie1_20260727T180000Z' => '2026-07-27'] as $id => $date) {
            $this->events[$id] = $this->googleEvent("{$date} 15:00", "{$date} 16:00", ['id' => $id, 'recurringEventId' => 'serie1', 'organizer' => ['self' => true]]);
        }
        $listed = collect($this->getJson($this->listUrl())->assertOk()->json('data.events'))->keyBy('id');
        $this->assertTrue($listed['serie1_20260720T180000Z']['recurring']);

        $this->installGoogle();
        $this->deleteJson('/api/google-calendar/events/serie1_20260720T180000Z')->assertNoContent();

        $writes = $this->writes();
        $this->assertCount(1, $writes);
        $this->assertSame('DELETE', $writes[0]['request']->getMethod());
        $this->assertStringEndsWith('/events/serie1_20260720T180000Z', $writes[0]['request']->getUri()->getPath());
        $this->assertArrayHasKey('serie1_20260727T180000Z', $this->events);
    }

    public function test_deleted_event_frees_availability(): void
    {
        $this->seedCalendar();
        $this->events = ['own' => $this->events['own']];
        $availability = app(AppointmentAvailabilityService::class);
        $start = CarbonImmutable::parse('2026-07-20 15:30', self::TZ);
        $this->assertTrue($availability->hasConflict($this->owner, $start, $start->addMinutes(30)));

        $this->deleteJson('/api/google-calendar/events/own')->assertNoContent();
        $this->assertFalse($availability->hasConflict($this->owner, $start, $start->addMinutes(30)));
    }

    public function test_disconnected_calendar_and_held_lock(): void
    {
        $this->seedCalendar();
        $this->connection->update(['status' => 'needs_reauth']);
        $this->getJson($this->listUrl())->assertUnprocessable()->assertJsonPath('code', 'calendar_not_connected');
        $this->deleteJson('/api/google-calendar/events/own')->assertUnprocessable()->assertJsonPath('code', 'calendar_not_connected');

        $this->connection->update(['status' => 'connected']);
        Sleep::fake(syncWithCarbon: true);
        $held = Cache::lock("ai-booking:owner:{$this->owner->id}", 60);
        $this->assertTrue($held->get());
        $this->deleteJson('/api/google-calendar/events/own')->assertStatus(409)->assertJsonPath('code', 'calendar_busy');
        $this->assertCount(0, $this->transport);
        $held->release();
    }

    private function listUrl(): string
    {
        return '/api/google-calendar/events?'.http_build_query(self::RANGE);
    }

    private function seedCalendar(): void
    {
        $me = ['email' => 'owner@example.com', 'self' => true];
        $blockMarkers = ['crm_availability_block' => '1', 'crm_tenant_id' => (string) $this->tenant->id, 'crm_user_id' => (string) $this->owner->id];
        $at = fn (string $id, array $extra = []) => $this->googleEvent('2026-07-20 10:00', '2026-07-20 11:00', ['id' => $id, 'organizer' => ['self' => true], ...$extra]);

        $this->events = [
            'own' => $this->googleEvent('2026-07-20 15:00', '2026-07-20 17:00', [
                'id' => 'own', 'organizer' => ['self' => true], 'attendees' => [['email' => 'x@y.com'], $me],
            ]),
            'invite' => $at('invite', ['organizer' => ['email' => 'boss@example.com', 'self' => false], 'transparency' => 'transparent']),
            'ownblock' => $at('ownblock', ['extendedProperties' => ['private' => $blockMarkers]]),
            'foreignblock' => $at('foreignblock', ['extendedProperties' => ['private' => [...$blockMarkers, 'crm_tenant_id' => (string) ($this->tenant->id + 1000)]]]),
            'mymeeting' => $at('mymeeting', ['extendedProperties' => ['private' => ['crm_task_id' => '7', 'crm_tenant_id' => (string) $this->tenant->id]]]),
            'othermeeting' => $at('othermeeting', ['extendedProperties' => ['private' => ['crm_task_id' => '8', 'crm_tenant_id' => (string) ($this->tenant->id + 1000)]]]),
            'cancelled' => $at('cancelled', ['status' => 'cancelled']),
            'working' => $at('working', ['eventType' => 'workingLocation']),
            'declined' => $at('declined', ['organizer' => ['self' => false], 'attendees' => [[...$me, 'responseStatus' => 'declined']]]),
            'birthday' => $at('birthday', ['eventType' => 'birthday']),
            'allday' => ['id' => 'allday', 'status' => 'confirmed', 'organizer' => ['self' => true], 'start' => ['date' => '2026-07-20'], 'end' => ['date' => '2026-07-21']],
        ];
    }
}
