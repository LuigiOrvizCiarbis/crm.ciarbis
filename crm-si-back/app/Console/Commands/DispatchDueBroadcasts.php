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
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->chunkById(50, function ($campaigns) use ($dispatcher): void {
                foreach ($campaigns as $campaign) {
                    $dispatcher->dispatch($campaign);
                }
            });

        return self::SUCCESS;
    }
}
