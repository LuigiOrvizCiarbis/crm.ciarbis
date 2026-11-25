<?php

namespace App\Console\Commands;

use App\Jobs\SyncTaskCalendarEventJob;
use App\Models\GoogleCalendarConnection;
use App\Models\Scopes\TenantScope;
use Illuminate\Console\Command;

/**
 * Publica en Google Calendar las tareas que todavía no llegaron al calendario
 * de su responsable. Es lo mismo que se encola al conectar la cuenta, para
 * todos los usuarios ya conectados: se corre una vez al desplegar los
 * recordatorios de tareas que no son reunión. Repetirlo es seguro, solo vuelve
 * a encolar lo que sigue sin sincronizar.
 */
class SyncPendingGoogleCalendarTasks extends Command
{
    protected $signature = 'google-calendar:sync-pending-tasks';

    protected $description = 'Encola el sync a Google Calendar de las tareas pendientes de cada usuario conectado.';

    public function handle(): int
    {
        $total = 0;

        GoogleCalendarConnection::withoutGlobalScope(TenantScope::class)
            ->where('status', 'connected')
            ->orderBy('id')
            ->each(function (GoogleCalendarConnection $connection) use (&$total): void {
                $queued = SyncTaskCalendarEventJob::dispatchPendingFor($connection->tenant_id, $connection->user_id);
                $total += $queued;

                if ($queued > 0) {
                    $this->line("tenant {$connection->tenant_id} · user {$connection->user_id}: {$queued}");
                }
            });

        $this->info("Tareas encoladas: {$total}");

        return Command::SUCCESS;
    }
}
