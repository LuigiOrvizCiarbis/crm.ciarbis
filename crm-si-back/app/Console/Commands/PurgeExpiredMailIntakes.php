<?php

namespace App\Console\Commands;

use App\Models\MailIntake;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredMailIntakes extends Command
{
    protected $signature = 'mail:purge-expired-intakes';

    protected $description = 'Elimina los emails descartados cuya ventana de restauración venció.';

    public function handle(): int
    {
        $intakes = MailIntake::query()->where('status', 'rejected')->whereNotNull('expires_at')->where('expires_at', '<=', now())->get();
        foreach ($intakes as $intake) {
            foreach ($intake->attachments ?? [] as $attachment) {
                $url = $attachment['url'] ?? '';
                if (str_starts_with($url, '/storage/')) {
                    Storage::disk('public')->delete(substr($url, strlen('/storage/')));
                }
            }
            $intake->delete();
        }
        $deleted = $intakes->count();
        $this->info("{$deleted} ingresos de email eliminados.");

        return self::SUCCESS;
    }
}
