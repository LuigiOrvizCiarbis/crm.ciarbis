<?php

namespace App\Console\Commands;

use App\Enums\BroadcastStatus;
use App\Models\BroadcastCampaign;
use App\Services\BroadcastDispatcher;
use Illuminate\Console\Command;

class DispatchDueBroadcasts extends Command
{
    protected $signature = 'broadcasts:dispatch-due';

    protected $description = 'Encola las difusiones programadas cuya fecha de lanzamiento ya venció';

    public function handle(BroadcastDispatcher $dispatcher): int
    {
        BroadcastCampaign::withoutGlobalScopes()
            ->where('status', BroadcastStatus::Scheduled)
            // scheduled_at es timestamptz: comparar en UTC. now() da hora local
            // y, sin ->utc(), el disparo se retrasa 3 horas. Ver HasTimezoneAwareDates.
            ->where('scheduled_at', '<=', now()->utc())
            ->chunkById(50, function ($campaigns) use ($dispatcher): void {
                foreach ($campaigns as $campaign) {
                    $dispatcher->dispatch($campaign);
                }
            });

        return self::SUCCESS;
    }
}
