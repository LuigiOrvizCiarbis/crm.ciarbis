<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreTaskDeadlinesAsAppWallClockMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_moves_stored_utc_deadlines_back_to_the_local_time_users_entered(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);

        // Filas como en producción: 10:30 locales guardadas como "13:30", y una
        // reunión con starts_at bien guardado pero deadline del ISO crudo.
        $followUp = $this->task($tenant->id, 'seguimiento', deadline: '2026-11-13 13:30:00');
        $meeting = $this->task($tenant->id, 'reunion', deadline: '2026-10-30 22:59:00', startsAt: '2026-10-30 19:59:00');
        $undated = $this->task($tenant->id, 'llamado', deadline: null);

        (require database_path('migrations/2026_11_18_000000_store_task_deadlines_as_app_wall_clock.php'))->up();

        $this->assertSame('2026-11-13 10:30:00', $this->deadline($followUp));
        $this->assertSame('2026-10-30 19:59:00', $this->deadline($meeting));
        $this->assertNull($this->deadline($undated));
    }

    private function task(int $tenantId, string $type, ?string $deadline, ?string $startsAt = null): int
    {
        return DB::table('tasks')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Tarea',
            'type' => $type,
            'status' => 'nuevo',
            'priority' => 'media',
            'deadline' => $deadline,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deadline(int $taskId): ?string
    {
        $value = DB::table('tasks')->where('id', $taskId)->value('deadline');

        return $value ? substr((string) $value, 0, 19) : null;
    }
}
