<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Services\Scheduling\AppointmentAvailabilityException;
use App\Services\Scheduling\AppointmentBookingService;
use App\Services\Scheduling\CalendarRequestBudget;
use App\Support\GoogleCalendarClient;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\AiSchedulingFixtures;
use Tests\TestCase;

class CalendarRequestBudgetTest extends TestCase
{
    use AiSchedulingFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        $this->resetSchedulingFakes();
        parent::tearDown();
    }

    public function test_each_request_uses_remaining_budget_without_mutating_the_shared_client(): void
    {
        $this->freezeMonday();
        $clock = 0.0;
        $budget = new CalendarRequestBudget(clock: static function () use (&$clock): float { return $clock; });
        $history = [];
        $transport = static function ($request, array $options) use (&$history): Response {
            $history[] = ['request' => $request, 'options' => $options];
            return new Response(200, [], '{"items":[]}');
        };
        $stack = HandlerStack::create(new MockHandler([$transport, $transport, $transport]));
        GoogleCalendarClient::$testHttpClient = new GuzzleClient(['handler' => $stack, 'timeout' => 20, 'connect_timeout' => 15]);
        $http = GoogleCalendarClient::make(budget: $budget)->getHttpClient();
        $http->request('GET', 'https://example.test/page1');
        $clock = 40.0;
        $http->request('GET', 'https://example.test/page2');
        $clock = 46.0;
        try {
            $http->request('GET', 'https://example.test/page3');
            $this->fail('An expired budget must not reach the transport');
        } catch (AppointmentAvailabilityException $e) {
            $this->assertSame(AppointmentAvailabilityException::GOOGLE_ERROR, $e->getCode());
        }
        // A normal SDK caller must not inherit the expired budget middleware.
        GoogleCalendarClient::make()->getHttpClient()->request('GET', 'https://example.test/independent');
        $this->assertSame([20.0, 5.0, 20], array_column(array_column($history, 'options'), 'timeout'));
        $this->assertSame([15.0, 5.0, 15], array_column(array_column($history, 'options'), 'connect_timeout'));
        $this->assertSame(['/page1', '/page2', '/independent'], array_map(fn ($entry) => $entry['request']->getUri()->getPath(), $history));
    }

    public function test_refresh_timeout_is_recoverable_and_does_not_revoke_connection(): void
    {
        $this->freezeMonday();
        [, $owner] = $this->makeTenantWithOwner();
        $connection = $this->connectGoogle($owner);
        $connection->update(['token_expires_at' => now()->subYears(10)]);
        GoogleCalendarClient::$testHttpClient = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([
            new ConnectException('Timed out', new Request('POST', 'https://oauth2.googleapis.com/token')),
        ]))]);
        try {
            GoogleCalendarClient::serviceFor($connection, new CalendarRequestBudget);
            $this->fail('The failed refresh must not produce an authenticated service');
        } catch (AppointmentAvailabilityException $e) {
            $this->assertSame(AppointmentAvailabilityException::GOOGLE_ERROR, $e->getCode());
        }
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertSame('refresh-token', $connection->fresh()->getDecryptedRefreshToken());
    }

    public function test_expired_availability_cannot_create_a_booking_and_releases_owner_lock(): void
    {
        $this->freezeMonday();
        [$tenant, $owner] = $this->makeTenantWithOwner();
        $this->connectGoogle($owner);
        $conversation = $this->makeConversation($tenant, $owner, $owner->id);
        $config = $this->makeSchedulingConfig($tenant);
        $clock = 0.0;
        $this->app->bind(CalendarRequestBudget::class, static function () use (&$clock) {
            return new CalendarRequestBudget(clock: static function () use (&$clock): float { return $clock; });
        });
        GoogleCalendarClient::$testHttpClient = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([
            static function () use (&$clock): Response {
                $clock = 46.0;
                return new Response(200, [], '{"items":[]}');
            },
            new Response(200, [], '{"items":[]}'),
        ]))]);
        $start = CarbonImmutable::parse('2026-07-21 11:00', self::TZ);
        try {
            app(AppointmentBookingService::class)->book($conversation, $config, $owner, $start, null, null, false);
            $this->fail('A Task must not be persisted after the deadline');
        } catch (AppointmentAvailabilityException $e) {
            $this->assertSame(AppointmentAvailabilityException::GOOGLE_ERROR, $e->getCode());
        }
        $this->assertSame(0, Task::withoutGlobalScopes()->count());
        // The next operation on the same owner gets a fresh budget and the lease.
        $task = app(AppointmentBookingService::class)->book($conversation, $config, $owner, $start, null, null, false);
        $this->assertSame($owner->id, $task->assigned_to);
        $this->assertSame(1, Task::withoutGlobalScopes()->count());
    }
}
