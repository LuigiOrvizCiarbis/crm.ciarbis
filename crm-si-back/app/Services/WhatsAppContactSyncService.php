<?php

namespace App\Services;

use App\Models\WhatsAppConfig;
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
        }

        $config->forceFill($attributes)->save();
    }

    public function markCompleted(WhatsAppConfig $config): void
    {
        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_COMPLETED,
            'contact_sync_error' => null,
        ])->save();
    }

    public function markFailed(WhatsAppConfig $config, string $error): void
    {
        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_FAILED,
            'contact_sync_error' => Str::limit($error, 500),
        ])->save();
    }

    /**
     * Reintenta el POST /smb_app_data dentro de la ventana de 24h.
     *
     * Meta documenta que el sync "can only be performed once per onboarding flow"
     * y NO documenta qué devuelve al repetirlo. Reintentamos igual porque el caso
     * que queremos cubrir es el disparo que nunca prendió, y un rechazo de Meta es
     * información útil (queda en contact_sync_error), no un daño: el sync ya está
     * consumido de todos modos. Si Meta lo rechaza, el estado termina en `failed`,
     * que es la señal correcta de que hace falta re-onboardear.
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

        $version = config('services.facebook.graph_version', 'v21.0');

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/smb_app_data", [
                    'messaging_product' => 'whatsapp',
                    'sync_type' => 'smb_app_state_sync',
                ]);

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
                ])->save();

                return true;
            }

            $body = $response->json();
            $errorMessage = strtolower((string) data_get($body, 'error.message', ''));

            // La WABA dejó de ser accesible con este token (asset desvinculado en
            // Meta, permisos revocados). Reintentar no lo arregla: es terminal.
            $assetGone = data_get($body, 'error.code') === 100
                && str_contains($errorMessage, 'does not exist');

            if ($assetGone) {
                $this->markFailed(
                    $config,
                    'La cuenta de WhatsApp ya no es accesible desde el CRM. '.
                    'Hay que reconectar el canal.'
                );

                return false;
            }

            Log::warning('retrySync: Meta rechazó el reintento', [
                'whatsapp_config_id' => $config->id,
                'status' => $response->status(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('retrySync exception', [
                'whatsapp_config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
