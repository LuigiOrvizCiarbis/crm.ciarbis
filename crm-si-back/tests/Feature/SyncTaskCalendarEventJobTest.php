<?php

namespace Tests\Feature;

use App\Jobs\SyncTaskCalendarEventJob;
use App\Models\Contact;
use App\Models\GoogleCalendarConnection;
use App\Models\Task;
use App\Models\TaskCalendarSync;
use App\Models\Tenant;
use App\Models\User;
use App\Support\GoogleCalendarClient;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncTaskCalendarEventJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google_calendar.client_id', 'test-client-id');
        config()->set('services.google_calendar.client_secret', 'test-client-secret');
        config()->set('services.google_calendar.redirect_uri', 'http://localhost/api/google-calendar/callback');
    }

    protected function tearDown(): void
    {
        GoogleCalendarClient::$testHttpClient = null;

        parent::tearDown();
    }

    public function test_non_meeting_task_is_ignored(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $task = Task::create(['tenant_id' => $tenant->id, 'name' => 'Seguimiento', 'type' => 'seguimiento']);

        // Sin mock HTTP: si el job intentara llamar a Google, fallaría por
        // falta de conexión de red y el test no pasaría igual.
        (new SyncTaskCalendarEventJob($task->id, 'upsert'))->handle();

        $this->assertSame(0, TaskCalendarSync::count());
    }

    public function test_upsert_creates_event_with_meet_and_marks_synced(): void
    {
        [$tenant, $user, $connection] = $this->makeConnectedUser();
        $contact = Contact::create(['tenant_id' => $tenant->id, 'name' => 'Cliente', 'email' => 'cliente@example.com', 'source' => 'manual']);

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $user->id,
            'contact_id' => $contact->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $this->mockGoogleResponses([
            new Response(200, [], json_encode([
                'id' => 'evt123',
                'htmlLink' => 'https://calendar.google.com/event?eid=evt123',
                'conferenceData' => [
                    'entryPoints' => [
                        ['entryPointType' => 'video', 'uri' => 'https://meet.google.com/abc-defg-hij'],
                    ],
                ],
            ])),
        ]);

        (new SyncTaskCalendarEventJob($task->id, 'upsert'))->handle();

        $sync = TaskCalendarSync::where('task_id', $task->id)->first();
        $this->assertNotNull($sync);
        $this->assertSame('synced', $sync->status);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $sync->meet_link);
        $this->assertNotEmpty($sync->external_event_id);
    }

    public function test_upsert_retries_as_update_on_409_conflict(): void
    {
        [$tenant, $user] = $this->makeConnectedUser();

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $this->mockGoogleResponses([
            new Response(409, [], json_encode(['error' => ['code' => 409, 'message' => 'The requested identifier already exists.']])),
            new Response(200, [], json_encode([
                'id' => 'evt123',
                'conferenceData' => ['entryPoints' => []],
            ])),
            new Response(200, [], json_encode([
                'id' => 'evt123',
                'htmlLink' => 'https://calendar.google.com/event?eid=evt123',
            ])),
        ]);

        (new SyncTaskCalendarEventJob($task->id, 'upsert'))->handle();

        $sync = TaskCalendarSync::where('task_id', $task->id)->first();
        $this->assertSame('synced', $sync->status);
    }

    public function test_upsert_marks_pending_when_owner_not_connected(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        (new SyncTaskCalendarEventJob($task->id, 'upsert'))->handle();

        $sync = TaskCalendarSync::where('task_id', $task->id)->first();
        $this->assertSame('pending', $sync->status);
        $this->assertSame('not_connected', $sync->last_error);
    }

    public function test_upsert_marks_connection_for_reauth_when_google_rejects_scope(): void
    {
        [$tenant, $user, $connection] = $this->makeConnectedUser();

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $this->mockGoogleResponses([
            new Response(403, [], json_encode([
                'error' => [
                    'code' => 403,
                    'message' => 'Request had insufficient authentication scopes.',
                    'status' => 'PERMISSION_DENIED',
                    'details' => [['reason' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT']],
                ],
            ])),
        ]);

        (new SyncTaskCalendarEventJob($task->id, 'upsert'))->handle();

        $sync = TaskCalendarSync::where('task_id', $task->id)->firstOrFail();
        $this->assertSame('pending', $sync->status);
        $this->assertSame('needs_reauth', $sync->last_error);
        $this->assertSame('needs_reauth', $connection->fresh()->status);
    }

    public function test_upsert_rethrows_quota_errors_so_the_queue_can_retry(): void
    {
        [$tenant, $user] = $this->makeConnectedUser();
        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
        ]);

        $this->mockGoogleResponses([
            new Response(403, [], json_encode(['error' => [
                'code' => 403,
                'message' => 'Quota exceeded',
                'errors' => [['reason' => 'quotaExceeded']],
            ]])),
        ]);

        $this->expectException(GoogleServiceException::class);
        try {
            (new SyncTaskCalendarEventJob($task->id, 'upsert'))->handle();
        } finally {
            $this->assertDatabaseHas('task_calendar_syncs', [
                'task_id' => $task->id,
                'status' => 'pending',
                'last_error' => 'retrying',
            ]);
        }
    }

    public function test_recurrence_is_sent_as_an_rrule_and_conference_request_is_unique(): void
    {
        [$tenant, $user] = $this->makeConnectedUser();
        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reunión recurrente',
            'type' => 'reunion',
            'assigned_to' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'recurrence' => 'FREQ=WEEKLY;BYDAY=MO',
        ]);
        $sync = new TaskCalendarSync(['task_id' => $task->id, 'tenant_id' => $tenant->id]);
        $method = new \ReflectionMethod(SyncTaskCalendarEventJob::class, 'buildEvent');
        $method->setAccessible(true);

        $event = $method->invoke(new SyncTaskCalendarEventJob($task->id, 'upsert'), $task, $sync);
        $requestId = $event->getConferenceData()->getCreateRequest()->getRequestId();

        $this->assertSame(['RRULE:FREQ=WEEKLY;BYDAY=MO'], $event->getRecurrence());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $requestId);
    }

    public function test_pending_meet_creation_is_polled_and_event_becomes_synced(): void
    {
        [$tenant, $user] = $this->makeConnectedUser();
        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reunión con Meet pendiente',
            'type' => 'reunion',
            'assigned_to' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
        ]);
        Queue::fake();
        $this->mockGoogleResponses([
            new Response(200, [], json_encode(['id' => 'evt123', 'conferenceData' => [
                'createRequest' => ['status' => ['statusCode' => 'pending']],
            ]])),
            new Response(200, [], json_encode(['id' => 'evt123', 'htmlLink' => 'https://calendar.google.com/event?eid=evt123', 'conferenceData' => [
                'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/abc-defg-hij']],
            ]])),
        ]);

        (new SyncTaskCalendarEventJob($task->id, 'upsert'))->handle();
        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->action === 'refresh_conference');

        (new SyncTaskCalendarEventJob($task->id, 'refresh_conference', conferencePoll: 1))->handle();
        $this->assertSame('synced', TaskCalendarSync::where('task_id', $task->id)->value('status'));
    }

    public function test_cancel_action_deletes_event_and_marks_paused(): void
    {
        [$tenant, $user] = $this->makeConnectedUser();

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $sync = TaskCalendarSync::create([
            'tenant_id' => $tenant->id,
            'task_id' => $task->id,
            'owner_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'external_event_id' => 'evt123',
            'status' => 'synced',
        ]);

        $this->mockGoogleResponses([
            new Response(204),
        ]);

        (new SyncTaskCalendarEventJob($task->id, 'cancel'))->handle();

        $sync->refresh();
        $this->assertSame('paused', $sync->status);
    }

    public function test_cancel_rethrows_retryable_google_error_and_keeps_sync_unpaused(): void
    {
        [$tenant, $user] = $this->makeConnectedUser();

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $sync = TaskCalendarSync::create([
            'tenant_id' => $tenant->id,
            'task_id' => $task->id,
            'owner_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'external_event_id' => 'evt123',
            'status' => 'synced',
        ]);

        $this->mockGoogleResponses([
            new Response(503, [], json_encode(['error' => ['code' => 503, 'message' => 'Backend Error']])),
        ]);

        // El evento sigue en Google: el job tiene que fallar para que la cola
        // reintente, no dar el borrado por hecho.
        $this->expectException(GoogleServiceException::class);

        try {
            (new SyncTaskCalendarEventJob($task->id, 'cancel'))->handle();
        } finally {
            $this->assertSame('synced', $sync->fresh()->status);
        }
    }

    public function test_cancel_marks_paused_when_event_is_already_gone(): void
    {
        [$tenant, $user] = $this->makeConnectedUser();

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $sync = TaskCalendarSync::create([
            'tenant_id' => $tenant->id,
            'task_id' => $task->id,
            'owner_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'external_event_id' => 'evt123',
            'status' => 'synced',
        ]);

        $this->mockGoogleResponses([
            new Response(404, [], json_encode(['error' => ['code' => 404, 'message' => 'Not Found']])),
        ]);

        (new SyncTaskCalendarEventJob($task->id, 'cancel'))->handle();

        $this->assertSame('paused', $sync->fresh()->status);
    }

    public function test_failed_marks_sync_as_error_after_retries_are_exhausted(): void
    {
        [$tenant, $user] = $this->makeConnectedUser();

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $sync = TaskCalendarSync::create([
            'tenant_id' => $tenant->id,
            'task_id' => $task->id,
            'owner_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'external_event_id' => 'evt123',
            'status' => 'synced',
        ]);

        (new SyncTaskCalendarEventJob($task->id, 'cancel'))->failed(new \RuntimeException('Backend Error'));

        $sync->refresh();
        $this->assertSame('error', $sync->status);
        $this->assertSame('Backend Error', $sync->last_error);
    }

    public function test_cancel_previous_rethrows_retryable_google_error(): void
    {
        [$tenant, $user] = $this->makeConnectedUser();

        $this->mockGoogleResponses([
            new Response(429, [], json_encode(['error' => ['code' => 429, 'message' => 'Rate Limit Exceeded']])),
        ]);

        $this->expectException(GoogleServiceException::class);

        (new SyncTaskCalendarEventJob(1, 'cancel_previous', $user->id, 'primary', 'evt123'))->handle();
    }

    public function test_cancel_previous_deletes_event_after_task_was_deleted(): void
    {
        [$tenant, $user] = $this->makeConnectedUser();

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $user->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $taskId = $task->id;
        $task->delete();

        $mock = $this->mockGoogleResponses([
            new Response(204),
        ]);

        // La tarea ya no existe: el job debe borrar el evento igual, apoyado
        // sólo en el snapshot, o el evento queda huérfano en el calendario.
        (new SyncTaskCalendarEventJob($taskId, 'cancel_previous', $user->id, 'primary', 'evt123'))->handle();

        // El mock consumió su respuesta ⇒ el DELETE llegó a Google.
        $this->assertCount(0, $mock, 'El job no llamó a Google para borrar el evento');
    }

    /**
     * @return array{0: Tenant, 1: User, 2: GoogleCalendarConnection}
     */
    private function makeConnectedUser(): array
    {
        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $connection = GoogleCalendarConnection::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'google_email' => 'user@example.com',
            'access_token' => 'placeholder',
            'refresh_token' => 'placeholder',
            'token_expires_at' => now()->addHour(),
            'scopes' => GoogleCalendarClient::SCOPES,
            'status' => 'connected',
        ]);
        $connection->setEncryptedAccessToken('access-token');
        $connection->setEncryptedRefreshToken('refresh-token');
        $connection->save();

        return [$tenant, $user, $connection];
    }

    private function mockGoogleResponses(array $responses): MockHandler
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        GoogleCalendarClient::$testHttpClient = new GuzzleClient(['handler' => $handlerStack]);

        return $mock;
    }
}
