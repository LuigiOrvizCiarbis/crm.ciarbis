<?php

namespace App\Console\Commands;

use App\Models\WhatsAppConfig;
use App\Services\WhatsAppContactSyncService;
use Illuminate\Console\Command;

/**
 * Re-dispara la sincronización de contactos de coexistencia de un canal.
 *
 * Sirve para rescatar un onboarding cuyo sync no llegó, siempre que siga abierta
 * la ventana de 24h que da Meta. Pasada esa ventana no hay nada que hacer desde
 * acá: el cliente tiene que reconectar el canal.
 */
class SyncWhatsAppContacts extends Command
{
    protected $signature = 'whatsapp:sync-contacts
                            {config : ID de whatsapp_configs}
                            {--force : Reintentar aunque la ventana de 24h haya vencido}';

    protected $description = 'Re-dispara la sincronización de contactos de la WhatsApp Business App (coexistencia)';

    public function handle(WhatsAppContactSyncService $service): int
    {
        $config = WhatsAppConfig::withoutGlobalScopes()->find($this->argument('config'));

        if (! $config) {
            $this->error('No existe la config indicada.');

            return self::FAILURE;
        }

        $this->line("Canal: {$config->display_phone_number} (phone_number_id {$config->phone_number_id})");
        $this->line('Estado actual: '.($config->contact_sync_status ?? 'sin datos'));
        $this->line('Contactos sincronizados hasta ahora: '.$config->contact_sync_contacts_count);

        if (! $config->isWithinContactSyncWindow() && ! $this->option('force')) {
            $this->warn(
                'La ventana de 24h de Meta venció el '.
                $config->contactSyncWindowExpiresAt()?->toDateTimeString().'. '.
                'Meta ya no acepta el sync: hay que desconectar el canal y volver a conectarlo. '.
                'Usá --force sólo para confirmarlo contra la API.'
            );

            return self::FAILURE;
        }

        if (! $service->retrySync($config)) {
            $this->error('Meta rechazó el pedido. Detalle: '.($config->fresh()->contact_sync_error ?? 'sin detalle'));

            return self::FAILURE;
        }

        $this->info('Sync pedida. Los contactos llegan por webhook en los próximos minutos.');
        $this->line('Verificá con: php artisan whatsapp:sync-contacts '.$config->id);

        return self::SUCCESS;
    }
}
