<?php

namespace App\Services;

use App\Models\WhatsAppConfig;
use App\Support\MetaOAuth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lee el messaging limit del número de WhatsApp desde Graph API.
 *
 * El messaging limit es la cantidad máxima de números de usuario ÚNICOS a los
 * que se puede entregar un mensaje fuera de una ventana de atención (customer
 * service window) en una ventana móvil de 24h.
 *
 * Dos detalles que importan y son fáciles de leer mal:
 *
 * 1. El límite se calcula y se asigna a nivel BUSINESS PORTFOLIO, no por número.
 *    Todos los números de la cartera comparten el mismo cupo, así que un número
 *    puede consumirlo entero y dejar sin envío a los demás. Por eso el valor que
 *    devuelve este servicio es un techo compartido, no un presupuesto propio.
 *
 * 2. El campo `messaging_limit_tier` está DEPRECADO. El vigente es
 *    `whatsapp_business_manager_messaging_limit`, disponible desde Graph v25.
 *    Como el proyecto corre por defecto en v21, se consulta con una versión
 *    mínima propia (ver GRAPH_VERSION) en lugar de la global.
 *
 * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/messaging-limits
 */
class WhatsAppMessagingLimitService
{
    /**
     * El campo vigente existe recién a partir de v25; la versión global del
     * proyecto (v21) no lo devuelve.
     */
    private const GRAPH_VERSION = 'v25.0';

    private const CACHE_TTL_MINUTES = 30;

    /** Tier de arranque de toda cartera nueva. */
    public const DEFAULT_TIER = 250;

    /**
     * Meta expone el límite como string (`TIER_250`, `TIER_1K`, …). No es un
     * número, así que hay que mapearlo.
     */
    private const TIER_VALUES = [
        'TIER_50' => 50,
        'TIER_250' => 250,
        'TIER_1K' => 1_000,
        'TIER_2K' => 2_000,
        'TIER_10K' => 10_000,
        'TIER_100K' => 100_000,
        'TIER_UNLIMITED' => null,
    ];

    /**
     * Devuelve el messaging limit del número, o null si no se pudo determinar.
     *
     * `limit` en null con `tier` = TIER_UNLIMITED significa "sin tope".
     * `limit` en null con `tier` en null significa "no se pudo leer": en ese
     * caso el llamador NO debe asumir un valor, porque tanto sobreestimar como
     * subestimar el tier lleva a decisiones equivocadas.
     *
     * @return array{tier: string|null, limit: int|null, unlimited: bool, known: bool}
     */
    public function forConfig(WhatsAppConfig $config): array
    {
        $cacheKey = "wa:messaging_limit:{$config->id}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->fetch($config);

        // Sólo se cachea una lectura exitosa: si Graph falló, conviene
        // reintentar en la próxima estimación en vez de congelar el fallo.
        if ($result['known']) {
            Cache::put($cacheKey, $result, now()->addMinutes(self::CACHE_TTL_MINUTES));
        }

        return $result;
    }

    public function forget(WhatsAppConfig $config): void
    {
        Cache::forget("wa:messaging_limit:{$config->id}");
    }

    /**
     * @return array{tier: string|null, limit: int|null, unlimited: bool, known: bool}
     */
    private function fetch(WhatsAppConfig $config): array
    {
        $token = $config->getDecryptedToken();

        if (! $token || ! $config->phone_number_id) {
            return $this->unknown();
        }

        try {
            $version = self::GRAPH_VERSION;

            $response = Http::withToken($token)
                ->timeout(15)
                ->get("https://graph.facebook.com/{$version}/{$config->phone_number_id}", [
                    'fields' => 'whatsapp_business_manager_messaging_limit',
                ]);

            if (! $response->successful()) {
                Log::warning('messagingLimit: Graph devolvió error', [
                    'phone_number_id' => $config->phone_number_id,
                    'http_status' => $response->status(),
                    'error' => MetaOAuth::describeMetaError($response->json()),
                ]);

                return $this->unknown();
            }

            $tier = $response->json('whatsapp_business_manager_messaging_limit');

            if (! is_string($tier) || $tier === '') {
                return $this->unknown();
            }

            return $this->fromTier($tier);
        } catch (\Throwable $e) {
            Log::warning('messagingLimit exception', MetaOAuth::describeException($e));

            return $this->unknown();
        }
    }

    /**
     * @return array{tier: string|null, limit: int|null, unlimited: bool, known: bool}
     */
    private function fromTier(string $tier): array
    {
        $normalized = strtoupper($tier);

        // Un tier que no está en el mapa es un valor nuevo de Meta, no un error:
        // se reporta como desconocido para no inventar un techo.
        if (! array_key_exists($normalized, self::TIER_VALUES)) {
            Log::info('messagingLimit: tier no reconocido', ['tier' => $tier]);

            return $this->unknown();
        }

        $limit = self::TIER_VALUES[$normalized];

        return [
            'tier' => $normalized,
            'limit' => $limit,
            'unlimited' => $limit === null,
            'known' => true,
        ];
    }

    /**
     * @return array{tier: null, limit: null, unlimited: false, known: false}
     */
    private function unknown(): array
    {
        return ['tier' => null, 'limit' => null, 'unlimited' => false, 'known' => false];
    }
}
