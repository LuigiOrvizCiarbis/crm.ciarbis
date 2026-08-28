<?php

namespace App\Console\Commands;

use App\Enums\AutomationRunStatus;
use App\Jobs\ExecuteAutomationRunJob;
use App\Models\AutomationRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDueAutomations extends Command
{
    protected $signature = 'automations:dispatch-due';

    protected $description = 'Encola las ejecuciones de automatización cuya fecha ya venció';

    /**
     * If dispatch() never reached the queue (crash/deploy right after commit),
     * the run is stuck in Queued with no job to claim it. Anything still
     * Queued after this grace period is treated as orphaned and re-dispatched.
     */
    private const STALE_QUEUED_MINUTES = 5;

    public function handle(): int
    {
        $this->reclaimStaleQueued();

        do {
            $ids = DB::transaction(function (): array {
                // scheduled_for y queued_at son timestamptz: comparar en UTC.
                // Ver HasTimezoneAwareDates.
                $runs = AutomationRun::withoutGlobalScopes()->where('status', AutomationRunStatus::Scheduled)
                    ->where('scheduled_for', '<=', now()->utc())
                    ->orderBy('scheduled_for')
                    ->limit(100)
                    ->lock('for update skip locked')
                    ->get();
                if ($runs->isEmpty()) {
                    return [];
                }
                $ids = $runs->modelKeys();
                // Bulk update: no pasa por el modelo, HasTimezoneAwareDates no
                // aplica. ->utc() explícito, igual que en las comparaciones de arriba.
                AutomationRun::withoutGlobalScopes()->whereKey($ids)->update(['status' => AutomationRunStatus::Queued, 'queued_at' => now()->utc()]);

                return $ids;
            });
            foreach ($ids as $id) {
                ExecuteAutomationRunJob::dispatch($id);
            }
        } while (count($ids) === 100);

        return self::SUCCESS;
    }

    private function reclaimStaleQueued(): void
    {
        do {
            // queued_at es timestamptz: comparar en UTC. Ver HasTimezoneAwareDates.
            $ids = AutomationRun::withoutGlobalScopes()->where('status', AutomationRunStatus::Queued)
                ->where('queued_at', '<=', now()->utc()->subMinutes(self::STALE_QUEUED_MINUTES))
                ->orderBy('queued_at')->limit(100)->pluck('id');
            foreach ($ids as $id) {
                ExecuteAutomationRunJob::dispatch($id);
            }
        } while ($ids->count() === 100);
    }
}
