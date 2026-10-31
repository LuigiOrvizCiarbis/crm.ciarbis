<?php

namespace App\Console\Commands;

use App\Enums\ScheduledMessageStatus;
use App\Jobs\SendScheduledMessageJob;
use App\Models\ScheduledMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchScheduledMessages extends Command
{
    protected $signature = 'messages:dispatch-scheduled';

    protected $description = 'Encola los mensajes programados del chat cuya hora ya llegó';

    /**
     * Si dispatch() nunca llegó a la cola (crash/deploy justo después del
     * commit), el mensaje queda en Queued sin job. Pasado este margen se
     * reencola; el claim queued → sending del job evita el doble envío.
     */
    private const STALE_QUEUED_MINUTES = 5;

    private const CHUNK = 100;

    public function handle(): int
    {
        $this->reclaimStaleQueued();

        do {
            $ids = DB::transaction(function (): array {
                // scheduled_for y queued_at son timestamptz: comparar en UTC.
                // Ver HasTimezoneAwareDates.
                $ids = ScheduledMessage::withoutGlobalScopes()
                    ->where('status', ScheduledMessageStatus::Scheduled)
                    ->where('scheduled_for', '<=', now()->utc())
                    ->orderBy('scheduled_for')
                    ->limit(self::CHUNK)
                    ->lock('for update skip locked')
                    ->pluck('id')
                    ->all();
                if ($ids === []) {
                    return [];
                }
                // Bulk update: no pasa por el modelo, HasTimezoneAwareDates no
                // aplica. ->utc() explícito, igual que en la comparación.
                ScheduledMessage::withoutGlobalScopes()->whereKey($ids)
                    ->update(['status' => ScheduledMessageStatus::Queued, 'queued_at' => now()->utc()]);

                return $ids;
            });
            foreach ($ids as $id) {
                SendScheduledMessageJob::dispatch($id);
            }
        } while (count($ids) === self::CHUNK);

        return self::SUCCESS;
    }

    private function reclaimStaleQueued(): void
    {
        $ids = ScheduledMessage::withoutGlobalScopes()
            ->where('status', ScheduledMessageStatus::Queued)
            // queued_at es timestamptz: comparar en UTC. Ver HasTimezoneAwareDates.
            ->where('queued_at', '<=', now()->utc()->subMinutes(self::STALE_QUEUED_MINUTES))
            ->orderBy('queued_at')
            ->limit(self::CHUNK)
            ->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        // Se renueva queued_at para no reencolar el mismo id cada minuto
        // mientras la cola está atrasada.
        ScheduledMessage::withoutGlobalScopes()->whereKey($ids)->update(['queued_at' => now()->utc()]);
        $ids->each(fn (int $id) => SendScheduledMessageJob::dispatch($id));
    }
}
