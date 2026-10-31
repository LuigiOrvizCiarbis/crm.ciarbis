<?php

namespace App\Services\Channels;

use App\Support\MetaOAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sentry\Severity;
use Sentry\State\Scope;

/**
 * Revoca la suscripción de webhooks que dejamos en Meta al conectar un canal.
 * Best-effort: nunca lanza. Un fallo acá no debe impedir la desconexión local
 * (ver ChannelDisconnector), pero tampoco debe pasar inadvertido — por eso
 * cada unsubscribe verifica con un GET posterior que la baja realmente pegó,
 * simétrico a lo que subscribeToWebhooks ya hace para el alta
 * (WhatsAppController::subscribeToWebhooks). Si la app sigue suscripta tras
 * el DELETE, se reporta a Sentry: es la única forma de detectar que el verbo,
 * la versión de Graph o los parámetros de esta baja (nunca antes usada en el
 * codebase) están mal, en vez de que el fallo quede invisible para siempre.
 */
class MetaWebhookUnsubscriber
{
    /** Misma versión que usó el subscribe de WhatsApp (WEBHOOK_SUBSCRIPTION_GRAPH_VERSION). */
    private const WHATSAPP_GRAPH_VERSION = 'v26.0';

    public function unsubscribeWaba(string $wabaId, string $token): bool
    {
        return $this->unsubscribe(
            $wabaId,
            $token,
            self::WHATSAPP_GRAPH_VERSION,
            'whatsapp',
        );
    }

    public function unsubscribePage(string $pageId, string $pageToken): bool
    {
        $version = config('services.facebook.graph_version', 'v26.0');

        return $this->unsubscribe($pageId, $pageToken, $version, 'page');
    }

    private function unsubscribe(string $id, string $token, string $version, string $context): bool
    {
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->delete("https://graph.facebook.com/{$version}/{$id}/subscribed_apps");

            if (! $response->successful()) {
                Log::warning("MetaWebhookUnsubscriber [{$context}]: DELETE subscribed_apps falló", [
                    'id' => $id,
                    'status' => $response->status(),
                    'error' => MetaOAuth::describeMetaError($response->json()),
                ]);

                return false;
            }

            return $this->verifyUnsubscribed($id, $token, $version, $context);
        } catch (\Throwable $e) {
            Log::warning(
                "MetaWebhookUnsubscriber [{$context}]: excepción desuscribiendo",
                MetaOAuth::describeException($e)
            );

            return false;
        }
    }

    /**
     * Confirma que nuestro app_id ya no figura entre las apps suscriptas.
     * Simétrico al chequeo de subscribeToWebhooks (WhatsAppController:783-805).
     */
    private function verifyUnsubscribed(string $id, string $token, string $version, string $context): bool
    {
        $check = Http::withToken($token)
            ->timeout(10)
            ->get("https://graph.facebook.com/{$version}/{$id}/subscribed_apps");

        if (! $check->successful()) {
            // No pudimos verificar; asumimos que el DELETE exitoso fue suficiente
            // en vez de reportar un falso positivo por un GET que falló solo.
            Log::warning("MetaWebhookUnsubscriber [{$context}]: no se pudo verificar la baja", [
                'id' => $id,
                'status' => $check->status(),
            ]);

            return true;
        }

        $appId = (string) config('services.facebook.app_id');
        $stillSubscribed = $appId !== '' && collect($check->json('data', []))
            ->contains(fn (array $app): bool => (string) (data_get($app, 'id')
                ?? data_get($app, 'whatsapp_business_api_data.id')) === $appId);

        if ($stillSubscribed) {
            Log::error("MetaWebhookUnsubscriber [{$context}]: la app sigue suscripta tras el DELETE", [
                'id' => $id,
                'app_id' => $appId,
            ]);

            $this->reportStillSubscribed($id, $context);

            return false;
        }

        return true;
    }

    private function reportStillSubscribed(string $id, string $context): void
    {
        if (! app()->bound('sentry')) {
            return;
        }

        \Sentry\withScope(function (Scope $scope) use ($id, $context): void {
            $scope->setContext('meta_unsubscribe', ['id' => $id, 'context' => $context]);
            \Sentry\captureMessage(
                "MetaWebhookUnsubscriber [{$context}]: la app sigue suscripta tras el DELETE de subscribed_apps",
                Severity::warning()
            );
        });
    }
}
