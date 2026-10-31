<?php

namespace App\Jobs;

use App\Automation\AutomationEngine;
use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ExecuteAutomationRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $runId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("automation-run:{$this->runId}"))->expireAfter(180)];
    }

    public function handle(AutomationEngine $engine): void
    {
        $run = AutomationRun::withoutGlobalScopes()->find($this->runId);
        if (! $run || ! $run->status->isPending()) {
            return;
        }
        $engine->execute($run);
    }

    public function failed(\Throwable $exception): void
    {
        // Bulk update: HasTimezoneAwareDates no aplica, ->utc() explícito.
        AutomationRun::withoutGlobalScopes()->whereKey($this->runId)
            ->whereIn('status', [AutomationRunStatus::Queued, AutomationRunStatus::Running])
            ->update([
                'status' => AutomationRunStatus::Failed,
                'error' => $exception->getMessage(),
                'finished_at' => now()->utc(),
            ]);
    }
}
