<?php

namespace Tests\Feature\Api;

use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaskScheduleDatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_deadline_and_start_date_are_stored_as_application_wall_time(): void
    {
        [, $user] = $this->createTenantAndOwner();
        Sanctum::actingAs($user);

        // 20:00Z = 17:00 en Buenos Aires; sin convertir se guardaba 20:00 y la
        // API lo devolvía tres horas corrido.
        $response = $this->postJson('/api/tasks', [
            'name' => 'Preparar propuesta',
            'deadline' => '2026-11-05T20:00:00.000Z',
            'start_date' => '2026-11-02T03:00:00.000Z',
        ])->assertCreated();

        $taskId = $response->json('data.id');
        $row = DB::table('tasks')->where('id', $taskId)->first();

        $this->assertSame('2026-11-05 17:00:00', $row->deadline);
        $this->assertSame('2026-11-02 00:00:00', $row->start_date);
        $this->assertNotNull($response->json('data.start_date'));
    }

    public function test_start_date_can_be_moved_and_cleared(): void
    {
        [$tenant, $user] = $this->createTenantAndOwner();
        Sanctum::actingAs($user);

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'name' => 'Visita a obra',
            'deadline' => '2026-11-10 12:00:00',
            'start_date' => '2026-11-08 00:00:00',
        ]);

        $this->putJson("/api/tasks/{$task->id}", [
            'start_date' => '2026-11-09T03:00:00.000Z',
            'deadline' => '2026-11-12T15:00:00.000Z',
        ])->assertOk();

        $this->assertSame('2026-11-09 00:00:00', $task->fresh()->getRawOriginal('start_date'));
        $this->assertSame('2026-11-12 12:00:00', $task->fresh()->getRawOriginal('deadline'));

        $this->putJson("/api/tasks/{$task->id}", ['start_date' => null])->assertOk();

        $this->assertNull($task->fresh()->start_date);
    }

    public function test_migration_moves_legacy_deadlines_back_three_hours_and_aligns_meetings(): void
    {
        [$tenant] = $this->createTenantAndOwner();
        $now = now()->format('Y-m-d H:i:s');

        $callId = DB::table('tasks')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'Llamado', 'type' => 'llamado',
            'status' => 'nuevo', 'priority' => 'media',
            'deadline' => '2026-11-05 20:00:00', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $meetingId = DB::table('tasks')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'Reunión', 'type' => 'reunion',
            'status' => 'nuevo', 'priority' => 'media',
            'deadline' => '2026-11-06 13:00:00', 'starts_at' => '2026-11-06 10:00:00',
            'ends_at' => '2026-11-06 11:00:00', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $migration = require database_path('migrations/2026_10_27_000001_fix_task_deadline_timezone_shift.php');
        $migration->up();

        $this->assertSame('2026-11-05 17:00:00', DB::table('tasks')->where('id', $callId)->value('deadline'));
        $this->assertSame('2026-11-06 10:00:00', DB::table('tasks')->where('id', $meetingId)->value('deadline'));
    }

    /** @return array{Tenant, User} */
    private function createTenantAndOwner(): array
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
