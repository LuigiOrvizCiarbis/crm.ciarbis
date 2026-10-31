<?php

namespace Tests\Feature\Api;

use App\Enums\AiBookingStatus;
use App\Jobs\SyncTaskCalendarEventJob;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Scheduling\AppointmentAvailabilityService;
use App\Services\Scheduling\AppointmentBookingException;
use App\Services\Scheduling\AppointmentBookingService;
use App\Services\Scheduling\AppointmentToolset;
use App\Services\Scheduling\AppointmentToolsetFactory;
use App\Services\Scheduling\CalendarRequestBudget;
use App\Services\WhatsAppMessageService;
use App\Support\GoogleCalendarClient;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create as PromiseCreate;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Psr\Http\Message\RequestInterface;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\AiSchedulingFixtures;
use Tests\TestCase;

class CalendarAvailabilityBlockTest extends TestCase
{
    use AiSchedulingFixtures, RefreshDatabase;

    private Tenant $tenant;
    private User $owner;
    private GoogleCalendarConnection $connection;
    private array $events = [];
    private array $history = [];
    private array $transport = [];
    private string $calendarTimezone = self::TZ;

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

    public function test_member_creates_private_synchronous_event_without_task_guests_meet_or_job(): void
    {
        $input = $this->rangeInput();
        $response = $this->postJson('/api/google-calendar/blocks', $input)->assertCreated();
        $id = $response->json('data.id');
        $this->assertSame('crmblock'.hash('sha256', $this->tenant->id.':'.$this->owner->id.':'.$input['request_id']), $id);
        $event = $this->events[$id];
        $this->assertSame('No disponible', $event['summary']);
        $this->assertSame('private', $event['visibility']);
        $this->assertSame('opaque', $event['transparency']);
        $this->assertFalse($event['reminders']['useDefault']);
        $this->assertSame(['dateTime' => '2026-07-20T15:00:00-03:00', 'timeZone' => self::TZ], $event['start']);
        $this->assertSame('2026-07-20T17:00:00-03:00', $event['end']['dateTime']);
        $this->assertSame((string) $this->owner->id, $event['extendedProperties']['private']['crm_user_id']);
        $this->assertSame((string) $this->tenant->id, $event['extendedProperties']['private']['crm_tenant_id']);
        foreach (['attendees', 'conferenceData', 'recurrence', 'description'] as $key) {
            $this->assertArrayNotHasKey($key, $event);
        }
        $write = $this->writes()[0]['request'];
        parse_str($write->getUri()->getQuery(), $query);
        $this->assertSame('none', $query['sendUpdates']);
        $this->assertDatabaseCount('tasks', 0);
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
    }

    public function test_rest_of_day_uses_google_local_now_and_next_local_midnight(): void
    {
        // 07:10 in Tokyo is still the previous day (19:10) for the app's Buenos Aires clock.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-21 07:10:59', 'Asia/Tokyo'));
        $this->calendarTimezone = 'Asia/Tokyo';
        $this->postJson('/api/google-calendar/blocks', [
            'request_id' => (string) Str::uuid(), 'mode' => 'rest_of_day', 'timezone' => 'Asia/Tokyo',
        ])->assertCreated()->assertJsonPath('data.start', '2026-07-21T07:10:00+09:00')
            ->assertJsonPath('data.end', '2026-07-22T00:00:00+09:00')->assertJsonPath('data.all_day', false);
    }

    public function test_day_starting_at_one_am_after_midnight_dst_jump_still_ends_at_next_midnight(): void
    {
        // Chile springs forward at 00:00 on 2026-09-06, so that day starts at 01:00.
        $tz = 'America/Santiago';
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 10:00', $tz));
        $this->calendarTimezone = $tz;
        $this->postJson('/api/google-calendar/blocks', ['request_id' => (string) Str::uuid(), 'mode' => 'rest_of_day', 'timezone' => $tz])
            ->assertCreated()->assertJsonPath('data.end', '2026-09-07T00:00:00-03:00');
        $this->events = ['early123' => [
            'id' => 'early123', 'status' => 'confirmed',
            'start' => ['dateTime' => '2026-09-07T00:00:00-03:00'], 'end' => ['dateTime' => '2026-09-07T00:30:00-03:00'],
        ]];
        // A full day ending on 09-06 must not extend into the next day's first hour.
        $this->postJson('/api/google-calendar/blocks', $this->daysInput('2026-09-06', '2026-09-06', $tz))
            ->assertCreated()->assertJsonPath('data.end', '2026-09-07');
    }

    public function test_inclusive_full_days_and_dst_are_calendar_dates_not_1440_minute_intervals(): void
    {
        $this->postJson('/api/google-calendar/blocks', $this->daysInput('2026-07-21', '2026-07-23'))
            ->assertCreated()->assertJsonPath('data.start', '2026-07-21')->assertJsonPath('data.end', '2026-07-24')->assertJsonPath('data.all_day', true);
        $this->calendarTimezone = 'America/New_York';
        $this->postJson('/api/google-calendar/blocks', $this->daysInput('2026-11-01', '2026-11-01', 'America/New_York'))
            ->assertCreated()->assertJsonPath('data.start', '2026-11-01')->assertJsonPath('data.end', '2026-11-02');
        $reads = array_values(array_filter($this->history, function ($entry) {
            parse_str($entry['request']->getUri()->getQuery(), $query);
            return isset($query['timeMin']) && ! isset($query['privateExtendedProperty']);
        }));
        parse_str($reads[1]['request']->getUri()->getQuery(), $query);
        $this->assertSame('2026-11-01T00:00:00-04:00', $query['timeMin']);
        $this->assertSame('2026-11-02T00:00:00-05:00', $query['timeMax']);
    }

    public function test_strict_dates_nonexistent_dst_and_nonapplicable_or_owner_fields_are_rejected(): void
    {
        $invalid = [
            $this->daysInput('2026-02-30', '2026-03-01'),
            [...$this->rangeInput(), 'timezone' => 'America/New_York', 'starts_at' => '2027-03-14 02:30', 'ends_at' => '2027-03-14 04:00'],
            [...$this->rangeInput(), 'starts_at' => '2026-7-20 15:00'],
            [...$this->rangeInput(), 'start_date' => '2026-07-20'],
            [...$this->rangeInput(), 'user_id' => $this->owner->id],
            [...$this->rangeInput(), 'tenant_id' => $this->tenant->id],
            [...$this->rangeInput(), 'calendar_id' => 'primary'],
            [...$this->rangeInput(), 'request_id' => 'bad'],
        ];
        foreach ($invalid as $input) {
            $this->postJson('/api/google-calendar/blocks', $input)->assertUnprocessable();
        }
        $this->assertCount(0, $this->history);
    }

    public function test_new_only_relative_rules_allow_started_today_but_reject_past_and_ended_ranges(): void
    {
        $this->postJson('/api/google-calendar/blocks', [...$this->rangeInput(), 'starts_at' => '2026-07-20 07:00'])
            ->assertCreated();
        foreach ([
            ['starts_at' => '2026-07-19 23:00'],
            ['starts_at' => '2026-07-20 06:00', 'ends_at' => '2026-07-20 07:00'],
            ['starts_at' => '2026-07-20 17:00', 'ends_at' => '2026-07-20 15:00'],
        ] as $changes) {
            $this->postJson('/api/google-calendar/blocks', [...$this->rangeInput(), ...$changes])->assertUnprocessable();
        }
        $this->assertCount(1, $this->writes());
    }

    public function test_overlap_warns_then_preserves_crm_proposal_and_google_meeting_on_confirmation(): void
    {
        $conversation = $this->makeConversation($this->tenant, $this->owner, $this->owner->id);
        $proposal = $this->makePendingBooking($conversation, $this->owner, '2026-07-20 15:30');
        $before = $proposal->fresh()->getAttributes();
        $this->events['meeting123'] = $this->googleEvent('2026-07-20 16:00', '2026-07-20 16:30', ['id' => 'meeting123']);
        $meeting = $this->events['meeting123'];
        $this->mock(WhatsAppMessageService::class, fn ($mock) => $mock->shouldNotReceive('sendSystemTextMessageFromCRM'));
        $input = $this->rangeInput();
        $this->postJson('/api/google-calendar/blocks', $input)->assertStatus(409)
            ->assertJsonPath('code', 'overlap_confirmation_required');
        $this->assertCount(0, $this->writes());
        $this->postJson('/api/google-calendar/blocks', [...$input, 'confirm_overlap' => true])->assertCreated();
        $writes = $this->writes();
        $this->assertCount(1, $writes, 'Confirming the overlap must not touch the existing meeting.');
        $this->assertSame('POST', $writes[0]['request']->getMethod());
        $this->assertStringEndsWith('/calendars/primary/events', $writes[0]['request']->getUri()->getPath());
        $this->assertSame($before, $proposal->fresh()->getAttributes());
        $this->assertSame($meeting, $this->events['meeting123']);
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
    }

    public function test_crm_proposal_alone_requires_overlap_confirmation(): void
    {
        $conversation = $this->makeConversation($this->tenant, $this->owner, $this->owner->id);
        $proposal = $this->makePendingBooking($conversation, $this->owner, '2026-07-20 15:30');
        $this->postJson('/api/google-calendar/blocks', $this->rangeInput())
            ->assertStatus(409)->assertJsonPath('code', 'overlap_confirmation_required');
        $this->assertSame(AiBookingStatus::PENDING, $proposal->fresh()->ai_booking_status);
        $this->assertCount(0, $this->writes());
    }

    public function test_create_and_delete_wait_on_the_owner_lock_shared_with_bookings(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $held = Cache::lock("ai-booking:owner:{$this->owner->id}", 60);
        $this->assertTrue($held->get());
        $this->postJson('/api/google-calendar/blocks', $this->rangeInput())
            ->assertStatus(409)->assertJsonPath('code', 'calendar_busy');
        $this->deleteJson('/api/google-calendar/blocks/block123')
            ->assertStatus(409)->assertJsonPath('code', 'calendar_busy');
        $this->assertCount(0, $this->transport, 'No Google call happens without the owner lock.');
        $held->release();
        $this->postJson('/api/google-calendar/blocks', $this->rangeInput())->assertCreated();
    }

    public function test_retry_after_uncertain_insert_recovers_same_event_without_duplicate_or_update(): void
    {
        $lost = false;
        $this->installGoogle(function (RequestInterface $request, array $options) use (&$lost): ?Response {
            if ($request->getMethod() === 'POST' && ! $lost) {
                $lost = true;
                $event = json_decode((string) $request->getBody(), true);
                $this->events[$event['id']] = [...$event, 'status' => 'confirmed'];
                throw new ConnectException('Response lost', $request);
            }
            return null;
        });
        $input = $this->rangeInput();
        $this->postJson('/api/google-calendar/blocks', $input)->assertStatus(503)
            ->assertJsonPath('message', 'No se pudo confirmar el bloqueo. Reintentá para comprobarlo.');
        $id = array_key_first($this->events);
        $this->postJson('/api/google-calendar/blocks', $input)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertCount(1, $this->events);
        $this->assertCount(1, $this->writes());
        $this->assertSame('connected', $this->connection->fresh()->status);
    }

    public function test_retry_after_midnight_and_timezone_change_precedes_new_only_rules(): void
    {
        $input = $this->daysInput('2026-07-20', '2026-07-23');
        $id = $this->postJson('/api/google-calendar/blocks', $input)->assertCreated()->json('data.id');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-21 09:00', self::TZ));
        $this->calendarTimezone = 'Asia/Tokyo';
        $this->postJson('/api/google-calendar/blocks', $input)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/google-calendar/blocks', [...$input, 'request_id' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'calendar_timezone_changed');
        $this->assertCount(1, $this->writes());
    }

    public function test_reused_request_with_different_payload_canceled_or_transparent_event_conflicts(): void
    {
        $input = $this->rangeInput();
        $id = $this->postJson('/api/google-calendar/blocks', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/google-calendar/blocks', [...$input, 'ends_at' => '2026-07-20 18:00'])
            ->assertStatus(409)->assertJsonPath('code', 'block_request_conflict');
        $this->events[$id]['transparency'] = 'transparent';
        $this->postJson('/api/google-calendar/blocks', $input)->assertStatus(409)->assertJsonPath('code', 'block_request_conflict');
        $this->events[$id]['transparency'] = 'opaque';
        $this->events[$id]['status'] = 'cancelled';
        $this->postJson('/api/google-calendar/blocks', $input)->assertStatus(409)->assertJsonPath('code', 'block_request_conflict');
        $this->assertCount(1, $this->writes());
    }

    public function test_insert_conflict_recovers_only_matching_private_opaque_event(): void
    {
        $makeTransparent = false;
        $this->installGoogle(function (RequestInterface $request) use (&$makeTransparent): ?Response {
            if ($request->getMethod() !== 'POST') {
                return null;
            }
            $event = json_decode((string) $request->getBody(), true);
            $this->events[$event['id']] = [...$event, 'transparency' => $makeTransparent ? 'transparent' : 'opaque'];
            return $this->errorResponse(409);
        });
        $this->postJson('/api/google-calendar/blocks', $this->rangeInput())->assertOk();
        $makeTransparent = true;
        $this->postJson('/api/google-calendar/blocks', [...$this->rangeInput(), 'confirm_overlap' => true])->assertStatus(409)->assertJsonPath('code', 'block_request_conflict');
        $this->assertCount(2, $this->writes());
    }

    public function test_list_paginates_filters_each_marker_and_never_returns_other_private_events(): void
    {
        $one = $this->ownedEvent('block123');
        $two = $this->ownedEvent('block456');
        $foreign = $this->ownedEvent('other123');
        $foreign['extendedProperties']['private']['crm_user_id'] = '999999';
        $otherWorkspace = $this->ownedEvent('other456');
        $otherWorkspace['extendedProperties']['private']['crm_tenant_id'] = '999999';
        $this->installGoogle(function (RequestInterface $request) use ($one, $two, $foreign, $otherWorkspace): ?Response {
            parse_str($request->getUri()->getQuery(), $query);
            if (! isset($query['privateExtendedProperty'])) {
                return null;
            }
            if (isset($query['pageToken'])) {
                return $this->jsonResponse(['items' => [$two]]);
            }
            return $this->jsonResponse(['items' => [
                $one, $foreign, $otherWorkspace,
                [...$one, 'id' => 'cancel123', 'status' => 'cancelled'],
                [...$one, 'id' => 'free123', 'transparency' => 'transparent'],
                $this->googleEvent('2026-07-20 10:00', '2026-07-20 11:00', ['id' => 'plain123']),
            ], 'nextPageToken' => 'page2']);
        });
        $result = $this->getJson('/api/google-calendar/blocks')->assertOk()
            ->assertJsonPath('data.google_email', $this->connection->google_email)
            ->assertJsonPath('data.timezone', self::TZ)->assertJsonPath('data.from_date', '2026-07-20')
            ->assertJsonPath('data.through_date', '2026-08-19')->assertJsonCount(2, 'data.blocks');
        $this->assertSame(['block123', 'block456'], array_column($result->json('data.blocks'), 'id'));
        $filters = [];
        foreach (explode('&', $this->history[1]['request']->getUri()->getQuery()) as $pair) {
            [$key, $value] = array_map('rawurldecode', explode('=', $pair, 2) + [1 => '']);
            if ($key === 'privateExtendedProperty') {
                $filters[] = $value;
            }
        }
        sort($filters);
        $this->assertSame([
            'crm_availability_block=1',
            'crm_tenant_id='.$this->tenant->id,
            'crm_user_id='.$this->owner->id,
        ], $filters);
        parse_str($this->history[2]['request']->getUri()->getQuery(), $query);
        $this->assertSame('page2', $query['pageToken']);
    }

    public function test_list_requires_both_strict_dates_and_at_most_93_inclusive_days(): void
    {
        foreach ([
            '?from_date=2026-07-20',
            '?from_date=2026-02-30&through_date=2026-03-01',
            '?from_date=2026-07-21&through_date=2026-07-20',
            '?from_date=2026-07-20&through_date=2026-10-21',
        ] as $query) {
            $this->getJson('/api/google-calendar/blocks'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/google-calendar/blocks?from_date=2026-07-20&through_date=2026-10-20')->assertOk();
    }

    public function test_delete_requires_self_workspace_markers_and_is_idempotent(): void
    {
        $this->events['block123'] = $this->ownedEvent('block123');
        $this->events['foreign123'] = $this->googleEvent('2026-07-20 15:00', '2026-07-20 17:00', ['id' => 'foreign123', 'summary' => 'No disponible']);
        $this->events['other123'] = $this->ownedEvent('other123');
        $this->events['other123']['extendedProperties']['private']['crm_tenant_id'] = '999999';
        $this->deleteJson('/api/google-calendar/blocks/foreign123')->assertNotFound();
        $this->deleteJson('/api/google-calendar/blocks/other123')->assertNotFound();
        $this->assertCount(0, $this->writes());
        $this->deleteJson('/api/google-calendar/blocks/block123')->assertNoContent();
        $this->deleteJson('/api/google-calendar/blocks/block123')->assertNoContent();
        $this->events['cancel123'] = [...$this->ownedEvent('cancel123'), 'status' => 'cancelled'];
        $this->deleteJson('/api/google-calendar/blocks/cancel123')->assertNoContent();
        $this->assertCount(1, $this->writes());
        $this->assertArrayHasKey('foreign123', $this->events);
        $this->deleteJson('/api/google-calendar/blocks/invalid-id')->assertUnprocessable();
    }

    public function test_delete_failure_preserves_event_and_delete_410_is_success(): void
    {
        $this->events['block123'] = $this->ownedEvent('block123');
        $fail = true;
        $this->installGoogle(function (RequestInterface $request) use (&$fail): ?Response {
            if ($request->getMethod() === 'DELETE') {
                if ($fail) {
                    throw new ConnectException('Timeout', $request);
                }
                return $this->errorResponse(410);
            }
            return null;
        });
        $this->deleteJson('/api/google-calendar/blocks/block123')->assertStatus(503);
        $this->assertArrayHasKey('block123', $this->events);
        $fail = false;
        $this->deleteJson('/api/google-calendar/blocks/block123')->assertNoContent();
    }

    public function test_missing_connection_reauth_missing_scope_and_insufficient_scope_403_are_recoverable(): void
    {
        $this->connection->update(['status' => 'needs_reauth']);
        $this->getJson('/api/google-calendar/blocks')->assertUnprocessable()->assertJsonPath('code', 'calendar_not_connected');
        $this->connection->update(['status' => 'connected', 'scopes' => ['openid']]);
        $this->getJson('/api/google-calendar/blocks')->assertUnprocessable()->assertJsonPath('code', 'calendar_not_connected');
        $this->assertSame('needs_reauth', $this->connection->fresh()->status);
        $this->connection->update(['status' => 'connected', 'scopes' => GoogleCalendarClient::SCOPES]);
        $this->installGoogle(fn () => $this->errorResponse(403, 'insufficientPermissions'));
        $this->getJson('/api/google-calendar/blocks')->assertUnprocessable()->assertJsonPath('code', 'calendar_not_connected');
        $this->assertSame('needs_reauth', $this->connection->fresh()->status);
        $this->connection->delete();
        $this->postJson('/api/google-calendar/blocks', $this->rangeInput())->assertUnprocessable()->assertJsonPath('code', 'calendar_not_connected');
    }

    public function test_insufficient_scope_during_overlap_read_is_unwrapped_and_marks_reauth(): void
    {
        $this->installGoogle(function (RequestInterface $request): ?Response {
            parse_str($request->getUri()->getQuery(), $query);
            return isset($query['timeMin']) ? $this->errorResponse(403, 'insufficientPermissions') : null;
        });
        $this->postJson('/api/google-calendar/blocks', $this->rangeInput())->assertUnprocessable()->assertJsonPath('code', 'calendar_not_connected');
        $this->assertSame('needs_reauth', $this->connection->fresh()->status);
        $this->assertCount(0, $this->writes());
    }

    public function test_network_and_invalid_calendar_zone_fail_without_success_or_reauth(): void
    {
        $this->installGoogle(function (RequestInterface $request): Response {
            throw new ConnectException('Timeout with private upstream details', $request);
        });
        $response = $this->getJson('/api/google-calendar/blocks')->assertStatus(503);
        $this->assertStringNotContainsString('private upstream', $response->getContent());
        $this->assertSame('connected', $this->connection->fresh()->status);
        $this->installGoogle(fn () => $this->jsonResponse(['items' => [], 'timeZone' => 'invalid/zone']));
        $this->getJson('/api/google-calendar/blocks')->assertStatus(503);
        $this->assertCount(0, $this->writes());
    }

    public function test_google_503_and_non_scope_403_are_service_errors_not_reauthorization(): void
    {
        foreach ([503, 403] as $status) {
            $this->installGoogle(fn () => $this->errorResponse($status, 'backendError'));
            $this->getJson('/api/google-calendar/blocks')->assertStatus(503)->assertJsonPath('code', 'calendar_google_error');
            $this->assertSame('connected', $this->connection->fresh()->status);
        }
        $this->installGoogle(fn () => $this->jsonResponse(['items' => []]));
        $this->getJson('/api/google-calendar/blocks')->assertStatus(503);
    }

    public function test_get_410_delete_is_already_unblocked_and_never_issues_delete(): void
    {
        $this->installGoogle(fn () => $this->errorResponse(410));
        $this->deleteJson('/api/google-calendar/blocks/gone123')->assertNoContent();
        $this->assertCount(0, $this->writes());
    }

    public function test_aggregate_budget_stops_pagination_and_never_inserts_after_45_seconds(): void
    {
        $clock = 0.0;
        $this->app->bind(CalendarRequestBudget::class, function () use (&$clock): CalendarRequestBudget {
            return new CalendarRequestBudget(45, function () use (&$clock): float { return $clock; });
        });
        $this->installGoogle(function (RequestInterface $request, array $options) use (&$clock): ?Response {
            $clock += 16;
            parse_str($request->getUri()->getQuery(), $query);
            if (isset($query['timeMin'])) {
                return $this->jsonResponse(['items' => [], 'nextPageToken' => 'another-page']);
            }
            return null;
        });
        $this->postJson('/api/google-calendar/blocks', $this->rangeInput())->assertStatus(503);
        $this->assertCount(3, $this->history, 'GET existing, timezone, and first availability page share one deadline.');
        $this->assertCount(0, $this->writes());
        $this->assertSame('connected', $this->connection->fresh()->status);
        $this->assertSame(20.0, (float) $this->transport[0]['options']['timeout']);
        $this->assertLessThanOrEqual(13, $this->transport[2]['options']['timeout']);
        // The lease is released after the failed attempt; a fresh budget can write.
        $clock = 0.0;
        $this->installGoogle();
        $this->postJson('/api/google-calendar/blocks', $this->rangeInput())->assertCreated();
    }

    public function test_listing_pages_share_one_budget_too(): void
    {
        $clock = 0.0;
        $this->app->bind(CalendarRequestBudget::class, function () use (&$clock): CalendarRequestBudget {
            return new CalendarRequestBudget(45, function () use (&$clock): float { return $clock; });
        });
        $this->installGoogle(function (RequestInterface $request) use (&$clock): ?Response {
            $clock += 16;
            parse_str($request->getUri()->getQuery(), $query);
            return isset($query['privateExtendedProperty']) ? $this->jsonResponse(['items' => [], 'nextPageToken' => 'again']) : null;
        });
        $this->getJson('/api/google-calendar/blocks')->assertStatus(503);
        $this->assertCount(3, $this->history);
    }

    public function test_workspace_header_uses_only_own_workspace_token_without_mutating_legacy_tenant(): void
    {
        [$workspaceB] = $this->makeTenantWithOwner();
        TenantMembership::create(['tenant_id' => $workspaceB->id, 'user_id' => $this->owner->id, 'joined_at' => now()]);
        $scoped = clone $this->owner;
        $scoped->setAttribute('tenant_id', $workspaceB->id);
        $connectionB = $this->connectGoogle($scoped);
        $connectionB->setEncryptedAccessToken('token-workspace-b');
        $connectionB->google_email = 'workspace-b@example.com';
        $connectionB->save();
        $this->withHeader('X-Workspace-Id', (string) $workspaceB->id);
        $this->getJson('/api/google-calendar/blocks')->assertOk()->assertJsonPath('data.google_email', 'workspace-b@example.com');
        $id = $this->postJson('/api/google-calendar/blocks', $this->rangeInput())->assertCreated()->json('data.id');
        $this->assertSame((string) $workspaceB->id, $this->events[$id]['extendedProperties']['private']['crm_tenant_id']);
        $this->makeSchedulingConfig($workspaceB, ['working_hours' => ['mon' => [['start' => '14:00', 'end' => '18:00']]]]);
        $conversation = $this->makeConversation($workspaceB, $scoped, $this->owner->id);
        $toolset = app(AppointmentToolsetFactory::class)->forConversation($conversation);
        $this->assertNotNull($toolset, 'An active B membership resolves the assigned owner despite the legacy A tenant.');
        $this->assertSame($workspaceB->id, $toolset->owner()->tenant_id);
        $this->assertSame(
            'Ese horario ya no está disponible. Consultá disponibilidad de nuevo y ofrecé otras opciones.',
            $toolset->call(AppointmentToolset::BOOK, ['inicio' => '2026-07-20 15:30']),
        );
        $this->assertNull($toolset->lastBooking());
        $this->assertDatabaseCount('tasks', 0);
        $proposal = $this->makePendingBooking($conversation, $scoped, '2026-07-20 15:30');
        try {
            app(AppointmentBookingService::class)->approve($proposal, $scoped);
            $this->fail('The proposal must consult and conflict with workspace B Google.');
        } catch (AppointmentBookingException $e) {
            $this->assertSame(AppointmentBookingException::SLOT_CONFLICT, $e->reason);
        }
        $this->assertSame(AiBookingStatus::PENDING, $proposal->fresh()->ai_booking_status);
        $this->deleteJson('/api/google-calendar/blocks/'.$id)->assertNoContent();
        foreach ($this->transport as $entry) {
            $this->assertSame('Bearer token-workspace-b', $entry['request']->getHeaderLine('Authorization'));
        }
        $this->assertSame($this->tenant->id, $this->owner->fresh()->tenant_id);
        TenantMembership::where('tenant_id', $workspaceB->id)->where('user_id', $this->owner->id)->delete();
        $this->getJson('/api/google-calendar/blocks')->assertForbidden();
    }

    public function test_google_block_prevents_slots_tool_booking_and_proposal_approval_then_deletion_restores_slots(): void
    {
        $conversation = $this->makeConversation($this->tenant, $this->owner, $this->owner->id);
        $proposalConversation = $this->makeConversation($this->tenant, $this->owner, $this->owner->id);
        $proposal = $this->makePendingBooking($proposalConversation, $this->owner, '2026-07-20 15:30');
        $config = $this->makeSchedulingConfig($this->tenant, ['working_hours' => ['mon' => [['start' => '14:00', 'end' => '18:00']]]]);
        $id = $this->postJson('/api/google-calendar/blocks', [...$this->rangeInput(), 'confirm_overlap' => true])->assertCreated()->json('data.id');
        $start = CarbonImmutable::parse('2026-07-20 15:00', self::TZ);
        $slots = app(AppointmentAvailabilityService::class)->availableSlots($config, $this->owner, $start, $start->addHours(2));
        $this->assertSame([], $slots);
        $toolset = new AppointmentToolset($conversation, $config, $this->owner, app(AppointmentAvailabilityService::class), app(AppointmentBookingService::class));
        $this->assertSame(
            'Ese horario ya no está disponible. Consultá disponibilidad de nuevo y ofrecé otras opciones.',
            $toolset->call(AppointmentToolset::BOOK, ['inicio' => '2026-07-20 15:30']),
        );
        $this->assertNull($toolset->lastBooking());
        $this->assertDatabaseCount('tasks', 1);
        $this->mock(WhatsAppMessageService::class, fn ($mock) => $mock->shouldNotReceive('sendSystemTextMessageFromCRM'));
        // Member manages blocks but task approval retains its existing permission.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->owner->syncRoles(['Owner']);
        $this->postJson('/api/tasks/'.$proposal->id.'/ai-booking/approve')->assertStatus(409)
            ->assertJsonPath('message', 'El horario ya está ocupado en la agenda del responsable');
        $this->assertSame(AiBookingStatus::PENDING, $proposal->fresh()->ai_booking_status);
        $this->deleteJson('/api/google-calendar/blocks/'.$id)->assertNoContent();
        $slots = app(AppointmentAvailabilityService::class)->availableSlots($config, $this->owner, $start, $start->addMinutes(30));
        $this->assertCount(1, $slots, 'A non-proposal slot is available again after deletion.');
        $this->assertSame('15:00', $slots[0]->format('H:i'));
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class);
    }

    public function test_half_open_adjacency_and_tokyo_all_day_block_use_correct_instants(): void
    {
        $this->events['meeting123'] = $this->googleEvent('2026-07-20 14:00', '2026-07-20 15:00', ['id' => 'meeting123']);
        $this->postJson('/api/google-calendar/blocks', $this->rangeInput())->assertCreated();
        $this->events = [];
        $this->calendarTimezone = 'Asia/Tokyo';
        $this->postJson('/api/google-calendar/blocks', $this->daysInput('2026-07-21', '2026-07-21', 'Asia/Tokyo'))->assertCreated();
        $availability = app(AppointmentAvailabilityService::class);
        $before = CarbonImmutable::parse('2026-07-20 11:30', self::TZ);
        $during = CarbonImmutable::parse('2026-07-20 12:00', self::TZ);
        $this->assertFalse($availability->hasConflict($this->owner, $before, $during));
        $this->assertTrue($availability->hasConflict($this->owner, $during, $during->addMinutes(30)));
    }

    private function rangeInput(): array
    {
        return ['request_id' => (string) Str::uuid(), 'mode' => 'time_range', 'timezone' => self::TZ, 'starts_at' => '2026-07-20 15:00', 'ends_at' => '2026-07-20 17:00'];
    }

    private function daysInput(string $start, string $end, string $timezone = self::TZ): array
    {
        return ['request_id' => (string) Str::uuid(), 'mode' => 'full_days', 'timezone' => $timezone, 'start_date' => $start, 'end_date' => $end];
    }

    private function ownedEvent(string $id): array
    {
        return $this->googleEvent('2026-07-20 15:00', '2026-07-20 17:00', [
            'id' => $id,
            'transparency' => 'opaque',
            'extendedProperties' => ['private' => ['crm_availability_block' => '1', 'crm_tenant_id' => (string) $this->tenant->id, 'crm_user_id' => (string) $this->owner->id]],
        ]);
    }

    /** Stateful Google transport: committed events are read by the real availability and booking services. */
    private function installGoogle(?callable $intercept = null): void
    {
        $this->history = [];
        $this->transport = [];
        $handler = function (RequestInterface $request, array $options) use ($intercept): mixed {
            $this->transport[] = ['request' => $request, 'options' => $options];
            try {
                if ($intercept && ($response = $intercept($request, $options))) {
                    return $response;
                }
            } catch (ConnectException $e) {
                return PromiseCreate::rejectionFor($e);
            }
            $path = $request->getUri()->getPath();
            if (preg_match('~/events/([^/]+)$~', $path, $match)) {
                $id = rawurldecode($match[1]);
                if (! isset($this->events[$id])) {
                    return $this->errorResponse(404);
                }
                if ($request->getMethod() === 'DELETE') {
                    unset($this->events[$id]);
                    return new Response(204);
                }
                return $this->jsonResponse($this->events[$id]);
            }
            if ($request->getMethod() === 'POST') {
                $event = json_decode((string) $request->getBody(), true);
                $this->events[$event['id']] = [...$event, 'status' => 'confirmed', 'htmlLink' => 'https://calendar.google.com/event?eid='.$event['id']];
                return $this->jsonResponse($this->events[$event['id']]);
            }
            return $this->jsonResponse(['timeZone' => $this->calendarTimezone, 'items' => array_values($this->events)]);
        };
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 150, $handler)));
        $stack->push(Middleware::history($this->history));
        GoogleCalendarClient::$testHttpClient = new Client(['handler' => $stack, 'http_errors' => false]);
    }

    private function jsonResponse(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function errorResponse(int $status, string $reason = 'notFound'): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode(['error' => ['code' => $status, 'message' => 'Google request failed', 'errors' => [['reason' => $reason]]]]));
    }

    private function writes(): array
    {
        return array_values(array_filter($this->history, fn ($entry) => in_array($entry['request']->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)));
    }
}
