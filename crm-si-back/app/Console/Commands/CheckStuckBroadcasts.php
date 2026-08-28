<?php

namespace App\Console\Commands;

use App\Enums\BroadcastRecipientStatus;
use App\Models\BroadcastRecipient;
use Illuminate\Console\Command;
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
        $stuck = BroadcastRecipient::withoutGlobalScopes()
            ->where('status', BroadcastRecipientStatus::Queued)
            ->where('queued_at', '<=', now()->utc()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->get(['id', 'broadcast_campaign_id', 'queued_at']);

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
