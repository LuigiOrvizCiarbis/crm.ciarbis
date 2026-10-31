<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeDeactivatedWorkspaces extends Command
{
    protected $signature = 'workspaces:purge {--dry-run : Mostrar sin borrar}';
    protected $description = 'Purga workspaces desactivados cuya ventana de recuperación venció';

    public function handle(): int
    {
        $workspaces = Tenant::query()->where('status', 'pending_deletion')->whereNotNull('deletion_scheduled_at')->where('deletion_scheduled_at', '<=', now())->get();
        foreach ($workspaces as $workspace) {
            if ($this->option('dry-run')) {
                $this->line("Purgaría workspace #{$workspace->id} ({$workspace->name})");
                continue;
            }
            DB::transaction(fn () => $workspace->delete());
            $this->info("Workspace #{$workspace->id} purgado.");
        }
        return self::SUCCESS;
    }
}
