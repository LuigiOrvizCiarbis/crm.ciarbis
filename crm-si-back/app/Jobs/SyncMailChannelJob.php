<?php

namespace App\Jobs;

use App\Models\MailConfig;
use App\Services\MailMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sincroniza una casilla IMAP: trae los emails nuevos y los persiste como
 * mensajes del canal MAIL.
 *
 * WithoutOverlapping por casilla es indispensable: dos corridas simultáneas
 * sobre el mismo cursor UID duplicarían mensajes o pisarían el cursor.
 */
class SyncMailChannelJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** IMAP puede ser lento: damos margen sin dejar el worker colgado. */
    public int $timeout = 180;

    public array $backoff = [60, 300];

    public function __construct(public int $mailConfigId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("mail-sync:{$this->mailConfigId}"))->expireAfter(300)];
    }

    public function handle(MailMessageService $service): void
    {
        // Sin scopes: el job corre fuera de un request autenticado.
        $config = MailConfig::withoutGlobalScopes()->find($this->mailConfigId);

        if (! $config) {
            return;
        }

        $created = $service->syncMailbox($config);

        if ($created > 0) {
            Log::info('Mail sync: mensajes nuevos importados', [
                'mail_config_id' => $config->id,
                'count' => $created,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        MailConfig::withoutGlobalScopes()
            ->whereKey($this->mailConfigId)
            ->update(['last_error' => Str::limit($exception->getMessage(), 500)]);
    }
}
