<?php

namespace App\Jobs;

use App\Models\BroadcastCampaign;
use App\Services\BroadcastDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Saca el disparo de una campaña "ahora" del request HTTP.
 *
 * BroadcastCampaignController::store() ya no llama a BroadcastDispatcher
 * directo: con audiencias de miles de contactos, el INSERT masivo de
 * recipients y el fan-out de un SendBroadcastMessageJob por destinatario no
 * entran en el timeout de PHP-FPM. Este job solo delega en el dispatcher
 * existente, que no cambia.
 */
class DispatchBroadcastCampaignJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $campaignId,
    ) {
        $this->onQueue('broadcasts');
    }

    public function handle(BroadcastDispatcher $dispatcher): void
    {
        $campaign = BroadcastCampaign::withoutGlobalScopes()->find($this->campaignId);

        if (! $campaign) {
            return;
        }

        $dispatcher->dispatch($campaign);
    }
}
