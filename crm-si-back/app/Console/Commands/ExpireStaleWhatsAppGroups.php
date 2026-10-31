<?php

namespace App\Console\Commands;

use App\Models\WhatsAppGroup;
use Illuminate\Console\Command;
use Sentry\Severity;
use Sentry\State\Scope;

class ExpireStaleWhatsAppGroups extends Command
{
    protected $signature = 'whatsapp-groups:expire-stale';

    protected $description = 'Marca como failed los grupos que quedaron en pending sin confirmación de Meta';

    /**
     * La creación es asíncrona: si group_lifecycle_update nunca llega (o el
     * ReconcileWhatsAppGroupJob agota sus reintentos), el grupo queda
     * "creando…" para siempre en la UI sin este watchdog.
     */
    private const STALE_AFTER_MINUTES = 15;

    public function handle(): int
    {
        $stale = WhatsAppGroup::withoutGlobalScopes()
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->get(['id', 'tenant_id', 'channel_id', 'request_id', 'created_at']);

        if ($stale->isEmpty()) {
            return self::SUCCESS;
        }

        WhatsAppGroup::withoutGlobalScopes()
            ->whereIn('id', $stale->pluck('id'))
            ->update([
                'status' => 'failed',
                'error_message' => 'Meta no confirmó la creación del grupo a tiempo.',
            ]);

        $message = sprintf('Grupos de WhatsApp expirados sin confirmación: %d', $stale->count());
        $this->warn($message);
        $this->reportStaleGroups($message, [
            'group_ids' => $stale->pluck('id')->all(),
            'oldest_created_at' => $stale->min('created_at'),
        ]);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $context */
    private function reportStaleGroups(string $message, array $context): void
    {
        if (! app()->bound('sentry')) {
            return;
        }

        \Sentry\withScope(function (Scope $scope) use ($message, $context): void {
            $scope->setContext('stale_whatsapp_groups', $context);
            \Sentry\captureMessage($message, Severity::warning());
        });
    }
}
