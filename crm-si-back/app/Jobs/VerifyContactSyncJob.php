<?php

namespace App\Jobs;

use App\Models\WhatsAppConfig;
use App\Services\WhatsAppContactSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Verifica que la sincronización de contactos de coexistencia haya llegado.
 *
 * El problema que resuelve: `POST /smb_app_data` devuelve 2xx apenas Meta acepta
 * el pedido, pero los contactos llegan después por webhooks `smb_app_state_sync`.
 * Si esos webhooks nunca llegaban (campo no suscrito, WABA desvinculada, sync que
 * Meta nunca completó), el onboarding se veía exitoso y el cliente se quedaba sin
 * contactos sin que nadie se enterara.
 *
 * Este job corre después del onboarding y espera los webhooks sin volver a pedir
 * una importación que Meta permite una sola vez. Pasada la ventana marca `failed`,
 * que es la señal de que hace falta offboardear y rehacer el Embedded Signup.
 */
class VerifyContactSyncJob implements ShouldQueue
{
    use Queueable;

    /** El reintento lo maneja el propio job re-despachándose, no el worker. */
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $whatsAppConfigId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("wa-contact-sync:{$this->whatsAppConfigId}"))->expireAfter(300)];
    }

    public function handle(WhatsAppContactSyncService $service): void
    {
        // Sin scopes: el job corre fuera de un request autenticado.
        $config = WhatsAppConfig::withoutGlobalScopes()->find($this->whatsAppConfigId);

        if (! $config) {
            return;
        }

        // Ya llegaron contactos: el webhook hizo su trabajo, no hay nada que hacer.
        if ($config->contact_sync_status === WhatsAppConfig::SYNC_COMPLETED) {
            return;
        }

        // Estado terminal: no reintentamos indefinidamente.
        if (in_array($config->contact_sync_status, [
            WhatsAppConfig::SYNC_FAILED,
            WhatsAppConfig::SYNC_NOT_APPLICABLE,
        ], true)) {
            return;
        }

        // Se importó al menos un contacto: la sincronización ocurrió de verdad.
        // No alcanza con "llegó un webhook": Meta emite smb_app_state_sync también
        // cuando el cliente toca su agenda a mano, así que un `remove` suelto no
        // prueba que la importación inicial haya pasado.
        if ($config->contact_sync_contacts_count > 0) {
            $service->markCompleted($config);

            return;
        }

        // No llegó nada. Si la ventana de Meta ya cerró, el sync es irrecuperable.
        if (! $config->isWithinContactSyncWindow()) {
            $service->markFailed(
                $config,
                'No llegaron contactos dentro de la ventana de 24h de Meta. '.
                'Hay que desconectar el canal y rehacer la conexión.'
            );

            Log::warning('VerifyContactSyncJob: ventana de 24h vencida sin contactos', [
                'whatsapp_config_id' => $config->id,
                'phone_number_id' => $config->phone_number_id,
            ]);

            return;
        }

        // Meta ya aceptó el pedido. No volvemos a ejecutar /smb_app_data porque la
        // importación inicial sólo se permite una vez por flujo de onboarding.
        if ($config->contact_sync_request_id || $config->contact_sync_retryable === false) {
            $config->forceFill(['contact_sync_retryable' => false])->save();

            Log::info('VerifyContactSyncJob: pedido aceptado, esperando webhooks', [
                'whatsapp_config_id' => $config->id,
                'phone_number_id' => $config->phone_number_id,
                'request_id' => $config->contact_sync_request_id,
            ]);

            self::dispatch($config->id)->delay(now()->addHours(1));

            return;
        }

        // Estado legado sin evidencia de aceptación: hacemos un único intento y
        // dejamos que el servicio persista si Meta lo aceptó o lo rechazó.
        $retried = $service->retrySync($config);

        Log::info('VerifyContactSyncJob: sin contactos todavía, reintento disparado', [
            'whatsapp_config_id' => $config->id,
            'phone_number_id' => $config->phone_number_id,
            'retry_ok' => $retried,
        ]);

        if ($retried) {
            self::dispatch($config->id)->delay(now()->addHours(1));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('VerifyContactSyncJob falló', [
            'whatsapp_config_id' => $this->whatsAppConfigId,
            'error' => $exception->getMessage(),
        ]);
    }
}
