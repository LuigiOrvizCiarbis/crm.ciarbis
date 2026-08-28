<?php

namespace App\Services;

use App\Models\WhatsAppConfig;
use App\Support\MetaOAuth;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sincronización de contactos de coexistencia (WhatsApp Business App → CRM).
 *
 * Centraliza las transiciones de estado para que el controller (onboarding), el
 * job de verificación y el comando artisan compartan la misma lógica en vez de
 * duplicarla.
 *
 * @see https://developers.facebook.com/docs/whatsapp/embedded-signup/onboarding-business-app-users
 */
class WhatsAppContactSyncService
{
    /**
     * Registra que llegó un lote de contactos por webhook.
     *
     * `smb_app_state_sync` no se emite sólo durante la importación inicial: Meta
     * también lo manda cuando el cliente agrega, edita o borra un contacto a mano
     * en la app. Por eso la llegada de un webhook NO prueba que la importación
     * haya ocurrido — un `remove` suelto, o un lote sin contactos válidos, llegan
     * igual. Sólo un lote que efectivamente importó contactos (upserted > 0)
     * cuenta como prueba de la sincronización.
     *
     * @param  int  $contactsInBatch  Contactos creados o actualizados en el lote.
     */
    public function recordWebhookBatch(WhatsAppConfig $config, int $contactsInBatch): void
    {
        $now = now();

        $attributes = ['contact_sync_last_webhook_at' => $now];

        if ($contactsInBatch > 0) {
            $attributes['contact_sync_status'] = WhatsAppConfig::SYNC_COMPLETED;
            $attributes['contact_sync_first_webhook_at'] = $config->contact_sync_first_webhook_at ?? $now;
            $attributes['contact_sync_contacts_count'] = $config->contact_sync_contacts_count + $contactsInBatch;
            $attributes['contact_sync_error'] = null;
            $attributes['contact_sync_retryable'] = false;
        }

        $config->forceFill($attributes)->save();
    }

    public function markCompleted(WhatsAppConfig $config): void
    {
        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_COMPLETED,
            'contact_sync_error' => null,
            'contact_sync_retryable' => false,
        ])->save();
    }

    public function markFailed(WhatsAppConfig $config, string $error, bool $retryable = true, ?string $errorCode = null): void
    {
        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_FAILED,
            'contact_sync_error' => Str::limit($error, 500),
            'contact_sync_error_code' => $errorCode,
            'contact_sync_retryable' => $retryable,
        ])->save();
    }

    /**
     * Persiste la última lectura del header X-App-Usage. Ver
     * WhatsAppController::recordMetaUsage / WhatsAppConfig::hasCriticalMetaUsage
     * para el porqué: es la cuota del business token del cliente, distinta de
     * la cuota de la app, y sólo visible leyendo este header.
     */
    private function recordMetaUsage(WhatsAppConfig $config, Response $response): void
    {
        $usagePct = MetaOAuth::parseAppUsage($response);

        if ($usagePct === null) {
            return;
        }

        $config->forceFill([
            'meta_app_usage_pct' => $usagePct,
            'meta_app_usage_at' => now(),
        ])->save();
    }

    /**
     * Reintenta el POST /smb_app_data dentro de la ventana de 24h.
     *
     * Meta documenta que el sync "can only be performed once per onboarding flow".
     * Este método sólo debe usarse cuando todavía no hay evidencia de que Meta
     * aceptó un pedido. El controller y el job evitan repetirlo una vez aceptado;
     * las defensas de este servicio preservan el estado ante llamadas concurrentes.
     *
     * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users
     */
    public function retrySync(WhatsAppConfig $config): bool
    {
        $phoneNumberId = $config->phone_number_id;
        $token = $config->getDecryptedToken();

        if (! $phoneNumberId || ! $token) {
            $this->markFailed($config, 'Falta phone_number_id o token para reintentar el sync.');

            return false;
        }

        // Guard preventivo: ver WhatsAppController::triggerContactSync para el
        // detalle. No consumimos el disparo único si ya sabemos, por una
        // lectura reciente del header X-App-Usage, que Meta lo va a rechazar.
        if ($config->hasCriticalMetaUsage()) {
            Log::warning('retrySync: cuota de Meta crítica, se omite el reintento', [
                'whatsapp_config_id' => $config->id,
                'meta_app_usage_pct' => $config->meta_app_usage_pct,
            ]);

            $this->markFailed(
                $config,
                'Meta está limitando las llamadas de la app. Esperá unos minutos y reintentá.',
                retryable: true,
                errorCode: 'rate_limit'
            );

            return false;
        }

        $version = config('services.facebook.graph_version', 'v21.0');

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/smb_app_data", [
                    'messaging_product' => 'whatsapp',
                    'sync_type' => 'smb_app_state_sync',
                ]);

            $this->recordMetaUsage($config, $response);

            if ($response->successful()) {
                // Queda en `syncing`, no en `completed`: Meta aceptó el pedido pero
                // los contactos siguen llegando por webhook. `requested_at` sólo se
                // fija la primera vez, porque ancla la ventana de 24h y un reintento
                // no la extiende.
                $config->forceFill([
                    'contact_sync_status' => WhatsAppConfig::SYNC_SYNCING,
                    'contact_sync_requested_at' => $config->contact_sync_requested_at ?? now(),
                    'contact_sync_request_id' => $response->json('request_id'),
                    'contact_sync_error' => null,
                    'contact_sync_retryable' => false,
                ])->save();

                // El paso 2 (historial) va encadenado al 1, igual que en el
                // onboarding. Sin esto, un onboarding que falla y se recupera con
                // el botón de reintentar traía los contactos pero perdía el
                // historial para siempre: la ventana de 24h se consume igual y
                // smb_app_data sólo acepta un pedido de history por onboarding.
                $this->triggerHistorySync($config, $token);

                return true;
            }

            $body = $response->json();
            $metaError = MetaOAuth::describeMetaError($body);
            $errorMessage = strtolower((string) data_get($body, 'error.message', ''));

            // Una llamada duplicada puede chocar con el límite de la aplicación.
            // Si ya tenemos el request_id del pedido aceptado, ese 403 no revierte
            // el estado real del sync ni reemplaza sus datos de seguimiento.
            $persistedConfig = (int) data_get($body, 'error.code') === 4
                ? $config->fresh()
                : null;

            if ($persistedConfig?->contact_sync_request_id) {
                $persistedConfig->forceFill([
                    'contact_sync_status' => WhatsAppConfig::SYNC_SYNCING,
                    'contact_sync_error' => null,
                    'contact_sync_retryable' => false,
                ])->save();

                Log::warning('retrySync: rate limit posterior a un pedido ya aceptado', [
                    'whatsapp_config_id' => $config->id,
                    'request_id' => $persistedConfig->contact_sync_request_id,
                    'status' => $response->status(),
                    'error' => $metaError,
                ]);

                return false;
            }

            // El código 190 identifica un access token inválido o vencido. No hay
            // reintento útil con la misma credencial: el canal debe reconectarse.
            if (data_get($body, 'error.code') === 190) {
                $this->markFailed(
                    $config,
                    'El acceso de Meta venció o fue revocado. Reconectá el canal para importar los contactos.',
                    false
                );

                Log::warning('retrySync: token de Meta inválido', [
                    'whatsapp_config_id' => $config->id,
                    'status' => $response->status(),
                    'error' => $metaError,
                ]);

                return false;
            }

            // La WABA dejó de ser accesible con este token (asset desvinculado en
            // Meta, permisos revocados). Reintentar no lo arregla: es terminal.
            $assetGone = data_get($body, 'error.code') === 100
                && str_contains($errorMessage, 'does not exist');

            if ($assetGone) {
                $this->markFailed(
                    $config,
                    'La cuenta de WhatsApp ya no es accesible desde el CRM. '.
                    'Hay que reconectar el canal.',
                    false
                );

                return false;
            }

            Log::warning('retrySync: Meta rechazó el reintento', [
                'whatsapp_config_id' => $config->id,
                'status' => $response->status(),
                'error' => $metaError,
            ]);

            $code = $metaError['code'] !== null ? " [{$metaError['code']}]" : '';
            $message = trim((string) ($metaError['message'] ?? ''));
            $detail = $message !== '' ? ': '.MetaOAuth::scrubMessage($message) : '.';

            // Código 4 sin request_id previo: primer rechazo por rate limit de
            // este onboarding (el caso ya cubierto arriba es un rechazo
            // *posterior* a un pedido ya aceptado). Igual clasificamos como
            // rate_limit para que el front muestre el mensaje traducido.
            $isRateLimit = (int) data_get($body, 'error.code') === 4;

            $this->markFailed(
                $config,
                "Meta rechazó la importación{$code}{$detail}",
                errorCode: $isRateLimit ? 'rate_limit' : null
            );

            return false;
        } catch (\Throwable $e) {
            Log::error('retrySync exception', [
                'whatsapp_config_id' => $config->id,
                'exception' => MetaOAuth::describeException($e),
            ]);

            $this->markFailed(
                $config,
                'No se pudo contactar a Meta para importar los contactos. Intentá nuevamente.'
            );

            return false;
        }
    }

    /**
     * Paso 2 de la coexistencia: pide el historial de mensajes.
     *
     * Best-effort: el historial es un complemento del sync de contactos y su
     * fallo nunca revierte el éxito del paso 1.
     *
     * El guard es un UPDATE condicional (no un if en PHP) para que dos requests
     * concurrentes no puedan pedirle el historial a Meta dos veces: sólo el que
     * gana la carrera afecta filas. Meta acepta un único pedido por onboarding y
     * los duplicados consumen cuota de rate limit de la app.
     *
     * Público porque además de encadenarse desde retrySync() (contact sync
     * exitoso), es el punto de reintento cuando el historial quedó `failed` en
     * rate_limit y el contact sync ya está `completed`: retryContactSync()
     * rechaza ese caso con 409 sin tocar el historial (ver retryHistorySync).
     *
     * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users
     */
    public function triggerHistorySync(WhatsAppConfig $config, string $token): void
    {
        $phoneNumberId = $config->phone_number_id;

        if (! $phoneNumberId) {
            return;
        }

        $reserved = WhatsAppConfig::where('id', $config->id)
            ->whereNull('contact_history_sync_requested_at')
            ->where(function ($query) {
                $query->whereNull('contact_history_sync_status')
                    ->orWhere('contact_history_sync_status', WhatsAppConfig::SYNC_PENDING);
            })
            ->update(['contact_history_sync_status' => WhatsAppConfig::SYNC_SYNCING]);

        if ($reserved === 0) {
            return;
        }

        $config->refresh();

        // Mismo guard preventivo que en retrySync(): si la última lectura de
        // X-App-Usage vio la cuota del business token en crítico y sigue
        // vigente, no llamamos. Un rechazo por cuota consumiría igual el único
        // disparo permitido de smb_app_data sin traer nada.
        if ($config->hasCriticalMetaUsage()) {
            Log::warning('triggerHistorySync: cuota de Meta crítica, se omite el pedido', [
                'whatsapp_config_id' => $config->id,
                'meta_app_usage_pct' => $config->meta_app_usage_pct,
            ]);

            $config->forceFill([
                'contact_history_sync_status' => WhatsAppConfig::SYNC_FAILED,
                'contact_history_sync_error' => 'Meta está limitando las llamadas de la app. Esperá unos minutos y reintentá.',
            ])->save();

            return;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post('https://graph.facebook.com/'.config('services.facebook.graph_version', 'v21.0')."/{$phoneNumberId}/smb_app_data", [
                    'messaging_product' => 'whatsapp',
                    'sync_type' => 'history',
                ]);

            $this->recordMetaUsage($config, $response);

            if ($response->successful()) {
                $config->forceFill([
                    'contact_history_sync_status' => WhatsAppConfig::SYNC_SYNCING,
                    'contact_history_sync_requested_at' => now(),
                    'contact_history_sync_request_id' => $response->json('request_id'),
                    'contact_history_sync_error' => null,
                ])->save();

                Log::info('retrySync: sync de historial pedida a Meta', [
                    'whatsapp_config_id' => $config->id,
                    'request_id' => $response->json('request_id'),
                ]);

                return;
            }

            Log::warning('retrySync: Meta rechazó el pedido de historial', [
                'whatsapp_config_id' => $config->id,
                'status' => $response->status(),
                'error' => MetaOAuth::describeMetaError($response->json()),
            ]);

            $config->forceFill([
                'contact_history_sync_status' => WhatsAppConfig::SYNC_FAILED,
                'contact_history_sync_error' => Str::limit(MetaOAuth::formatMetaError($response->json()), 500),
            ])->save();
        } catch (\Throwable $e) {
            Log::error('retrySync: excepción pidiendo el historial', [
                'whatsapp_config_id' => $config->id,
                'exception' => MetaOAuth::describeException($e),
            ]);

            $config->forceFill([
                'contact_history_sync_status' => WhatsAppConfig::SYNC_FAILED,
                'contact_history_sync_error' => Str::limit($e->getMessage(), 500),
            ])->save();
        }
    }

    /**
     * Reintenta sólo el historial. Existe porque triggerHistorySync() puede
     * quedar `failed` (por ejemplo, guard de cuota crítica) mientras el
     * contact sync ya está `completed` — retryContactSync() rechaza ese estado
     * con 409 sin tocar el historial, así que sin esto un rate limit temporal
     * y previo al request dejaba el historial bloqueado para siempre pese a
     * que el propio mensaje de error le decía al usuario que reintentara.
     *
     * No valida `retryable` (no existe un campo equivalente para el historial):
     * el guard atómico de triggerHistorySync ya impide duplicar el pedido a
     * Meta una vez que fue aceptado (contact_history_sync_requested_at seteado).
     * Si nunca se pidió con éxito, reintentar es siempre válido dentro de la
     * ventana de 24h del sync de contactos, que ambos pasos comparten.
     */
    public function retryHistorySync(WhatsAppConfig $config): bool
    {
        $token = $config->getDecryptedToken();

        if (! $token) {
            $config->forceFill([
                'contact_history_sync_status' => WhatsAppConfig::SYNC_FAILED,
                'contact_history_sync_error' => 'No se pudo obtener el token de Meta para reintentar el historial.',
            ])->save();

            return false;
        }

        // El guard atómico de triggerHistorySync sólo reserva el slot si el
        // status está en null/pending: un `failed` previo (guard de cuota,
        // excepción, o el propio 400/403 de Meta) lo deja afuera y el reintento
        // no haría nada. Si Meta nunca llegó a aceptar un pedido real
        // (requested_at sigue null), es seguro volver a `pending` para que el
        // guard deje pasar; si ya lo aceptó una vez, no se toca: ese pedido ya
        // consumió el disparo único y no hay que gastarlo de nuevo.
        if ($config->contact_history_sync_requested_at === null) {
            $config->forceFill(['contact_history_sync_status' => WhatsAppConfig::SYNC_PENDING])->save();
        }

        $this->triggerHistorySync($config, $token);

        return $config->fresh()->contact_history_sync_status === WhatsAppConfig::SYNC_SYNCING;
    }
}
