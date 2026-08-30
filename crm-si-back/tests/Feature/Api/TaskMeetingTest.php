<?php

namespace Tests\Feature\Api;

use App\Jobs\SyncTaskCalendarEventJob;
use App\Models\Task;
use App\Models\TaskCalendarSync;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaskMeetingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->user] = $this->createTenantAndUser();
        Sanctum::actingAs($this->user);
    }

    public function test_meeting_requires_assigned_to_and_start_end_and_timezone(): void
    {
        $response = $this->postJson('/api/tasks', [
            'name' => 'Reunión sin datos',
            'type' => 'reunion',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['assigned_to', 'starts_at', 'ends_at', 'meeting_timezone']);
    }

    public function test_meeting_ends_at_must_be_after_starts_at(): void
    {
        $response = $this->postJson('/api/tasks', [
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $this->user->id,
            'starts_at' => '2026-08-01T10:00:00-03:00',
            'ends_at' => '2026-08-01T09:00:00-03:00',
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ends_at']);
    }

    public function test_meeting_rejects_invalid_timezone(): void
    {
        $response = $this->postJson('/api/tasks', [
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $this->user->id,
            'starts_at' => '2026-08-01T10:00:00-03:00',
            'ends_at' => '2026-08-01T10:30:00-03:00',
            'meeting_timezone' => 'Not/AZone',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['meeting_timezone']);
    }

    public function test_meeting_normalizes_legacy_buenos_aires_timezone_alias(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/tasks', [
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $this->user->id,
            'starts_at' => '2026-08-01T10:00:00-03:00',
            'ends_at' => '2026-08-01T10:30:00-03:00',
            'meeting_timezone' => 'America/Buenos_Aires',
        ]);

        $response->assertCreated();
        $this->assertSame(
            'America/Argentina/Buenos_Aires',
            Task::query()->firstOrFail()->meeting_timezone,
        );
    }

    public function test_non_meeting_tasks_are_unaffected_by_new_validation(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/tasks', [
            'name' => 'Llamar al cliente',
            'type' => 'llamado',
        ]);

        $response->assertStatus(201);
        Queue::assertNothingPushed();
    }

    public function test_converting_task_to_meeting_requires_meeting_fields(): void
    {
        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Llamar al cliente',
            'type' => 'llamado',
        ]);

        // El editor Gantt manda exactamente este PATCH parcial: sin los campos
        // de reunión se guardaría una reunión incompleta y el job no crearía
        // el evento en Google.
        $response = $this->patchJson("/api/tasks/{$task->id}", [
            'type' => 'reunion',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['assigned_to', 'starts_at', 'ends_at', 'meeting_timezone']);
    }

    public function test_converting_task_to_meeting_succeeds_when_fields_are_provided(): void
    {
        Queue::fake();

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Llamar al cliente',
            'type' => 'llamado',
        ]);

        $response = $this->patchJson("/api/tasks/{$task->id}", [
            'type' => 'reunion',
            'assigned_to' => $this->user->id,
            'starts_at' => '2026-08-01T10:00:00-03:00',
            'ends_at' => '2026-08-01T10:30:00-03:00',
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $response->assertOk();
        Queue::assertPushed(SyncTaskCalendarEventJob::class);
    }

    public function test_converting_task_to_meeting_only_requires_the_fields_it_lacks(): void
    {
        Queue::fake();

        // La tarea ya trae responsable y horarios de antes: sólo falta la
        // timezone, así que el resto no hay que reenviarlo.
        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Llamar al cliente',
            'type' => 'llamado',
            'assigned_to' => $this->user->id,
            'starts_at' => '2026-08-01T10:00:00-03:00',
            'ends_at' => '2026-08-01T10:30:00-03:00',
        ]);

        $this->patchJson("/api/tasks/{$task->id}", ['type' => 'reunion'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['meeting_timezone'])
            ->assertJsonMissingValidationErrors(['assigned_to', 'starts_at', 'ends_at']);

        $this->patchJson("/api/tasks/{$task->id}", [
            'type' => 'reunion',
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ])->assertOk();
    }

    public function test_partial_update_of_existing_meeting_keeps_stored_fields(): void
    {
        Queue::fake();

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reunión',
            'type' => 'reunion',
            'assigned_to' => $this->user->id,
            'starts_at' => '2026-08-01T10:00:00-03:00',
            'ends_at' => '2026-08-01T10:30:00-03:00',
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        // Renombrar una reunión ya existente no debe exigir reenviar todo.
        $this->patchJson("/api/tasks/{$task->id}", ['name' => 'Reunión renombrada'])
            ->assertOk();

        $this->assertSame($this->user->id, $task->fresh()->assigned_to);
    }

    public function test_creating_meeting_dispatches_sync_job_and_sets_deadline_from_starts_at(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/tasks', [
            'name' => 'Reunión con cliente',
            'type' => 'reunion',
            'assigned_to' => $this->user->id,
            'starts_at' => '2026-08-01T10:00:00-03:00',
            'ends_at' => '2026-08-01T10:30:00-03:00',
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $response->assertStatus(201);

        $task = Task::first();
        $this->assertNotNull($task->deadline);
        $this->assertTrue($task->deadline->equalTo($task->starts_at));

        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->taskId === $task->id && $job->action === 'upsert');
    }

    public function test_cancelling_meeting_dispatches_cancel_job(): void
    {
        Queue::fake();
        $task = $this->makeMeeting();
        Queue::fake();

        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'cancelado'])->assertOk();

        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->taskId === $task->id && $job->action === 'cancel');
    }

    public function test_completing_meeting_does_not_cancel_event(): void
    {
        Queue::fake();
        $task = $this->makeMeeting();
        Queue::fake();

        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'hecho'])->assertOk();

        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->action === 'upsert');
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->action === 'cancel');
    }

    public function test_deleting_meeting_dispatches_cancel_with_event_snapshot(): void
    {
        Queue::fake();
        $task = $this->makeMeeting();
        $taskId = $task->id;

        $this->syncedCalendarRow($task, 'deletedeventid1234567');

        Queue::fake();

        $this->deleteJson("/api/tasks/{$task->id}")->assertOk();

        // La tarea y su sync caen por cascade, así que el job sólo puede borrar
        // el evento si lleva el snapshot: sin él quedaría huérfano en Google.
        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->taskId === $taskId
            && $job->action === 'cancel_previous'
            && $job->cancelEventId === 'deletedeventid1234567'
            && $job->cancelOwnerUserId === $this->user->id
            && $job->cancelCalendarId === 'primary');
    }

    public function test_changing_meeting_to_other_type_cancels_event_with_snapshot(): void
    {
        Queue::fake();
        $task = $this->makeMeeting();

        $sync = $this->syncedCalendarRow($task, 'droppedeventid1234567');

        Queue::fake();

        $this->patchJson("/api/tasks/{$task->id}", ['type' => 'seguimiento'])->assertOk();

        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->taskId === $task->id
            && $job->action === 'cancel_previous'
            && $job->cancelEventId === 'droppedeventid1234567');
        Queue::assertNotPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->action === 'upsert');

        $sync->refresh();
        $this->assertSame('paused', $sync->status);
    }

    public function test_reassigning_meeting_bumps_generation_and_cancels_previous_event(): void
    {
        Queue::fake();
        $task = $this->makeMeeting();

        // El job real crea el TaskCalendarSync; acá está fakeado, así que
        // simulamos el estado post-sync exitoso manualmente.
        $sync = TaskCalendarSync::create([
            'tenant_id' => $this->tenant->id,
            'task_id' => $task->id,
            'owner_user_id' => $this->user->id,
            'google_calendar_id' => 'primary',
            'external_event_id' => 'existingeventid123456',
            'event_generation' => 1,
            'status' => 'synced',
        ]);

        $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherUser->assignRole('Owner');

        Queue::fake();

        $this->patchJson("/api/tasks/{$task->id}", ['assigned_to' => $otherUser->id])->assertOk();

        $sync->refresh();
        $this->assertSame(2, $sync->event_generation);
        $this->assertSame('', $sync->external_event_id);
        $this->assertSame('pending', $sync->status);

        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->action === 'cancel_previous'
            && $job->cancelEventId === 'existingeventid123456');
        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->taskId === $task->id && $job->action === 'upsert');
    }

    public function test_retry_endpoint_requeues_sync_for_meeting_in_error(): void
    {
        Queue::fake();
        $task = $this->makeMeeting();

        TaskCalendarSync::where('task_id', $task->id)->update(['status' => 'error', 'last_error' => 'boom']);

        Queue::fake();

        $this->postJson("/api/tasks/{$task->id}/google-calendar/retry")->assertOk();

        $this->assertDatabaseHas('task_calendar_syncs', [
            'task_id' => $task->id,
            'status' => 'pending',
            'last_error' => 'retrying',
        ]);

        Queue::assertPushed(SyncTaskCalendarEventJob::class, fn ($job) => $job->taskId === $task->id && $job->action === 'upsert');
    }

    public function test_retry_endpoint_rejects_non_meeting_task(): void
    {
        Queue::fake();

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Seguimiento',
            'type' => 'seguimiento',
        ]);

        $this->postJson("/api/tasks/{$task->id}/google-calendar/retry")->assertStatus(422);
    }

    private function makeMeeting(): Task
    {
        $response = $this->postJson('/api/tasks', [
            'name' => 'Reunión con cliente',
            'type' => 'reunion',
            'assigned_to' => $this->user->id,
            'starts_at' => '2026-08-01T10:00:00-03:00',
            'ends_at' => '2026-08-01T10:30:00-03:00',
            'meeting_timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        return Task::find($response->json('data.id'));
    }

    /**
     * El job real crea/completa el TaskCalendarSync; en los tests está fakeado,
     * así que simulamos el estado post-sync exitoso.
     */
    private function syncedCalendarRow(Task $task, string $eventId): TaskCalendarSync
    {
        $sync = TaskCalendarSync::firstOrNew(['task_id' => $task->id]);

        $sync->fill([
            'tenant_id' => $this->tenant->id,
            'owner_user_id' => $this->user->id,
            'google_calendar_id' => 'primary',
            'external_event_id' => $eventId,
            'event_generation' => 1,
            'status' => 'synced',
        ])->save();

        return $sync;
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function createTenantAndUser(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');

        return [$tenant, $user];
    }
}
