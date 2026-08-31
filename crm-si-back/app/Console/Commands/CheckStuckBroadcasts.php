<?php

namespace App\Console\Commands;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\BroadcastStatus;
use App\Models\BroadcastRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Sentry\Severity;
use Sentry\State\Scope;

class CheckStuckBroadcasts extends Command
{
    protected $signature = 'broadcasts:check-stuck';

    protected $description = 'Alerta si hay destinatarios de difusión atascados en la cola';

    /**
     * Un destinatario queued no debería tardar más que el intervalo
     * configurado entre mensajes más un margen razonable. 15 minutos es el
     * síntoma que se vio en el incidente del retry_after mal configurado
     * (55 min de retraso): sin esta alerta, nadie se entera hasta que el
     * cliente reporta que el mensaje llegó tarde.
     */
    private const STUCK_AFTER_MINUTES = 15;

    public function handle(): int
    {
        // BroadcastDispatcher despacha cada job con delay = índice * interval_seconds,
        // índice asignado por orden de id dentro del batch encolado en el
        // mismo momento (mismo queued_at). Con audiencias grandes e
        // interval_seconds alto, un recipient legítimamente sigue "queued"
        // horas después de crear la campaña sin estar atascado — hay que
        // reconstruir ese mismo delay esperado antes de alertar, o
        // interval_seconds > 0 dispara falsos positivos masivos.
        // batch_index se calcula sobre TODO el batch (sin filtrar por status
        // acá dentro): filtrar por queued antes de numerar renumeraría hacia
        // cero a medida que los jobs anteriores del batch se van marcando
        // Sent/Failed. Un recipient que nació en el índice 100 (delay real de
        // 100 * interval_seconds) pasaría a verse como índice 3 apenas los 97
        // anteriores se procesaran, y el comando le exigiría un delay muchísimo
        // menor del que en verdad tiene, disparando un falso positivo. El
        // filtro de status va solo en la query externa.
        $stuck = BroadcastRecipient::withoutGlobalScopes()
            ->joinSub(
                DB::table('broadcast_recipients')
                    // Acotado a campañas Processing, no a recipients Queued:
                    // eso sí filtra por campaña completa, sin tocar qué filas
                    // del batch entran en el ROW_NUMBER() de cada una.
                    ->whereIn('broadcast_campaign_id', DB::table('broadcast_campaigns')
                        ->where('status', BroadcastStatus::Processing->value)
                        ->select('id'))
                    ->selectRaw('id, ROW_NUMBER() OVER (PARTITION BY broadcast_campaign_id, queued_at ORDER BY id) - 1 as batch_index'),
                'positions',
                'positions.id',
                '=',
                'broadcast_recipients.id',
            )
            ->join('broadcast_campaigns', 'broadcast_campaigns.id', '=', 'broadcast_recipients.broadcast_campaign_id')
            ->where('broadcast_recipients.status', BroadcastRecipientStatus::Queued)
            ->whereRaw(
                "broadcast_recipients.queued_at + (positions.batch_index * broadcast_campaigns.interval_seconds || ' seconds')::interval <= ?",
                [now()->utc()->subMinutes(self::STUCK_AFTER_MINUTES)]
            )
            ->get([
                'broadcast_recipients.id',
                'broadcast_recipients.broadcast_campaign_id',
                'broadcast_recipients.queued_at',
            ]);

        if ($stuck->isEmpty()) {
            return self::SUCCESS;
        }

        $campaignIds = $stuck->pluck('broadcast_campaign_id')->unique()->values()->all();
        $message = sprintf(
            'Difusiones atascadas: %d destinatario(s) en cola hace más de %d minutos, en %d campaña(s)',
            $stuck->count(),
            self::STUCK_AFTER_MINUTES,
            count($campaignIds),
        );

        $this->warn($message);
        $this->reportStuckBroadcasts($message, [
            'recipient_count' => $stuck->count(),
            'campaign_ids' => $campaignIds,
            'oldest_queued_at' => $stuck->min('queued_at'),
        ]);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $context */
    private function reportStuckBroadcasts(string $message, array $context): void
    {
        if (! app()->bound('sentry')) {
            return;
        }

        \Sentry\withScope(function (Scope $scope) use ($message, $context): void {
            $scope->setContext('stuck_broadcasts', $context);
            \Sentry\captureMessage($message, Severity::warning());
        });
    }
}
