<?php

namespace App\Services\Channels;

use App\Support\MetaOAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Suscribe la app a los webhooks de una página de Facebook.
 *
 * `message_echoes` es obligatorio y es un field SEPARADO en Messenger (en
 * Instagram los echoes vienen dentro de `messages`). Sin él no llegan los
 * mensajes que un agente escribe desde la app de Messenger, y la IA seguiría
 * respondiendo por encima de una persona: el handoff se rompe en silencio.
 *
 * `messaging_referrals` es obligatorio para recibir `message.referral` de los
 * anuncios Click-to-Messenger.
 */
class MessengerWebhookSubscriber
{
    public const FIELDS = 'messages,message_echoes,messaging_postbacks,messaging_referrals';

    public function subscribe(string $pageId, string $pageToken): bool
    {
        $version = config('services.facebook.graph_version', 'v26.0');

        try {
            $response = Http::withToken($pageToken)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => self::FIELDS,
                ]);

            if (! $response->successful()) {
                Log::error('Messenger subscribeToWebhooks failed', [
                    'status' => $response->status(),
                    'error' => MetaOAuth::describeMetaError($response->json()),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Messenger subscribeToWebhooks exception', MetaOAuth::describeException($e));

            return false;
        }
    }
}
