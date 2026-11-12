<?php

namespace App\Console\Commands;

use App\Models\MessengerConfig;
use App\Services\Channels\MessengerWebhookSubscriber;
use Illuminate\Console\Command;

/**
 * Re-suscribe las páginas ya conectadas con MessengerWebhookSubscriber::FIELDS.
 * Las páginas conectadas antes de agregar `messaging_referrals` no reciben el
 * anuncio de origen hasta correr este comando.
 */
class ResubscribeMessengerWebhooks extends Command
{
    /**
     * @var string
     */
    protected $signature = 'messenger:resubscribe-webhooks
                            {--dry-run : Listar sin llamar a Meta}';

    /**
     * @var string
     */
    protected $description = 'Re-suscribe los webhooks de las páginas de Messenger con canal activo';

    public function handle(MessengerWebhookSubscriber $subscriber): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔍 Modo DRY RUN — no se llama a Meta');
        }

        $configs = MessengerConfig::whereHas('channels', fn ($q) => $q->where('status', 'active'))
            ->orderBy('id')
            ->get();
        $this->info("Revisando {$configs->count()} páginas de Messenger...");

        $ok = 0;
        $failed = 0;

        foreach ($configs as $config) {
            $label = "[{$config->id}] página {$config->page_id}";
            $token = $config->getDecryptedToken();

            if ($token === null) {
                $this->warn("{$label}: sin token, reconectar desde la UI");
                $failed++;

                continue;
            }

            if ($dryRun) {
                $this->line("{$label}: se re-suscribiría");

                continue;
            }

            if ($subscriber->subscribe($config->page_id, $token)) {
                $this->line("{$label}: ok");
                $ok++;
            } else {
                $this->error("{$label}: Meta rechazó la suscripción");
                $failed++;
            }
        }

        $this->newLine();
        $this->info(sprintf('Resumen: ok=%d fallos=%d', $ok, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
