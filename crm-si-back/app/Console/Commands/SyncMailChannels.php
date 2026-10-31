<?php

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Jobs\SyncMailChannelJob;
use App\Models\MailConfig;
use Illuminate\Console\Command;

class SyncMailChannels extends Command
{
    protected $signature = 'mail:sync-channels';

    protected $description = 'Encola la sincronización IMAP de todas las casillas con canal de email activo';

    public function handle(): int
    {
        // Sólo casillas con un canal MAIL activo: un canal desconectado no debe
        // seguir golpeando el servidor IMAP del cliente.
        MailConfig::withoutGlobalScopes()
            ->whereHas('channels', function ($query): void {
                $query->withoutGlobalScopes()
                    ->where('type', ChannelType::MAIL)
                    ->where('status', 'active');
            })
            ->select('id')
            ->chunkById(100, function ($configs): void {
                foreach ($configs as $config) {
                    SyncMailChannelJob::dispatch($config->id);
                }
            });

        return self::SUCCESS;
    }
}
