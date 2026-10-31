<?php

namespace App\Services;

use App\Models\Product;
use App\Models\WooCommerceConfig;
use App\Support\PublicUrlGuard;
use App\Support\PublicUrlRejectedException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Conector con la REST API de WooCommerce (wc/v3). Prueba credenciales e importa
 * los productos de la tienda al catálogo local (source='woocommerce'), usando
 * WooCommerce como fuente de verdad (upsert idempotente por external_id).
 */
class WooCommerceService
{
    private const API_PREFIX = '/wp-json/wc/v3';

    private const PER_PAGE = 100;

    private const MAX_PAGES = 100; // Tope de seguridad: hasta 10.000 productos por sync.

    public function __construct(private PublicUrlGuard $urlGuard) {}

    /**
     * Prueba la conexión: pega a /products con page_size=1. Distingue credenciales
     * inválidas (401) de URL/tienda inaccesible.
     *
     * @return array{ok: bool, error_code: ?string, error_message: ?string}
     */
    public function testConnection(string $storeUrl, string $consumerKey, string $consumerSecret): array
    {
        try {
            $response = $this->request($storeUrl, $consumerKey, $consumerSecret)
                ->get('/products', ['per_page' => 1]);
        } catch (WooCommerceUrlException $e) {
            return [
                'ok' => false,
                'error_code' => 'invalid_url',
                'error_message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error_code' => 'unreachable',
                'error_message' => 'No se pudo conectar con la tienda. Revisá la URL.',
            ];
        }

        if ($response->status() === 401) {
            return [
                'ok' => false,
                'error_code' => 'invalid_credentials',
                'error_message' => 'Consumer Key/Secret inválidos o sin permisos de lectura.',
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error_code' => 'unknown',
                'error_message' => 'La tienda respondió con un error ('.$response->status().').',
            ];
        }

        return ['ok' => true, 'error_code' => null, 'error_message' => null];
    }

    /**
     * Sincroniza todos los productos de la tienda al catálogo del tenant.
     * WooCommerce es la fuente de verdad: crea los nuevos y pisa los existentes
     * (match por source='woocommerce' + external_id). No borra productos manuales
     * ni los que ya no estén en Woo.
     *
     * @return array{created: int, updated: int, total: int}
     *
     * @throws WooCommerceUrlException si la store_url no es una URL pública válida.
     */
    public function syncProducts(WooCommerceConfig $config): array
    {
        $storeUrl = $config->store_url;
        $key = $config->getDecryptedConsumerKey();
        $secret = $config->getDecryptedConsumerSecret();

        if (! $key || ! $secret) {
            return ['created' => 0, 'updated' => 0, 'total' => 0];
        }

        // Falla temprano ante una URL bloqueada, antes de tocar la DB.
        $this->assertPublicUrl($storeUrl);

        $created = 0;
        $updated = 0;
        $total = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->request($storeUrl, $key, $secret)->get('/products', [
                'per_page' => self::PER_PAGE,
                'page' => $page,
                'status' => 'publish',
            ]);

            if (! $response->successful()) {
                Log::warning('WooCommerce sync: página falló', [
                    'tenant_id' => $config->tenant_id,
                    'page' => $page,
                    'status' => $response->status(),
                ]);
                break;
            }

            $items = $response->json();

            if (! is_array($items) || count($items) === 0) {
                break;
            }

            foreach ($items as $item) {
                $result = $this->upsertProduct($config->tenant_id, $item);
                $result === 'created' ? $created++ : $updated++;
                $total++;
            }

            // Si la página vino incompleta, no hay más.
            if (count($items) < self::PER_PAGE) {
                break;
            }
        }

        $config->last_synced_at = Carbon::now();
        $config->save();

        return ['created' => $created, 'updated' => $updated, 'total' => $total];
    }

    /**
     * Inserta o actualiza un producto local a partir de un item de la API de Woo.
     *
     * @param  array<string, mixed>  $item
     * @return 'created'|'updated'
     */
    private function upsertProduct(int $tenantId, array $item): string
    {
        $externalId = (string) ($item['id'] ?? '');

        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source', 'woocommerce')
            ->where('external_id', $externalId)
            ->first();

        $exists = $product !== null;

        if (! $exists) {
            $product = new Product;
            $product->tenant_id = $tenantId;
            $product->source = 'woocommerce';
            $product->external_id = $externalId;
        }

        $product->name = (string) ($item['name'] ?? 'Sin nombre');
        $product->price = $this->parsePrice($item['price'] ?? null);
        $product->description = $this->cleanDescription($item);
        $product->is_active = ($item['status'] ?? 'publish') === 'publish';
        $product->save();

        return $exists ? 'updated' : 'created';
    }

    private function parsePrice(mixed $price): ?float
    {
        if ($price === null || $price === '') {
            return null;
        }

        return is_numeric($price) ? (float) $price : null;
    }

    /**
     * Toma la descripción corta (o la larga como fallback), le quita el HTML y
     * la normaliza. WooCommerce devuelve HTML en estos campos.
     *
     * @param  array<string, mixed>  $item
     */
    private function cleanDescription(array $item): ?string
    {
        $raw = $item['short_description'] ?? '';
        if (trim((string) $raw) === '') {
            $raw = $item['description'] ?? '';
        }

        $text = trim(Str::of((string) $raw)->stripTags()->squish()->value());

        return $text === '' ? null : $text;
    }

    /**
     * Construye un cliente HTTP autenticado contra la REST API de la tienda.
     *
     * @throws WooCommerceUrlException si la store_url no es una URL pública válida.
     */
    private function request(string $storeUrl, string $consumerKey, string $consumerSecret): PendingRequest
    {
        // La store_url la controla el tenant → validar contra SSRF antes de pegarle.
        // Devuelve host/puerto/IPs públicas ya validadas para pinnearlas en la conexión.
        [$host, $port, $ips] = $this->assertPublicUrl($storeUrl);

        $base = rtrim($storeUrl, '/').self::API_PREFIX;

        return Http::withBasicAuth($consumerKey, $consumerSecret)
            ->acceptJson()
            ->timeout(30)
            // Sin redirects automáticos: un 30x podría llevarnos a un host interno
            // que no pasó por assertPublicUrl().
            ->withOptions(['allow_redirects' => false])
            // Pin de la IP validada: cURL conecta exactamente a las IPs que
            // resolvimos y validamos, cerrando la ventana de DNS rebinding entre
            // la validación y la conexión real.
            ->withOptions(['curl' => [
                CURLOPT_RESOLVE => ["{$host}:{$port}:".implode(',', $ips)],
            ]])
            ->baseUrl($base);
    }

    /**
     * Valida que la store_url sea pública y segura de contactar (anti-SSRF),
     * delegando en PublicUrlGuard. Traduce su excepción a WooCommerceUrlException
     * para no cambiar el contrato que ya consumen los callers de este servicio.
     *
     * @return array{0: string, 1: int, 2: list<string>} host, puerto, IPs públicas validadas.
     *
     * @throws WooCommerceUrlException
     */
    private function assertPublicUrl(string $storeUrl): array
    {
        try {
            return $this->urlGuard->assertPublicUrl($storeUrl);
        } catch (PublicUrlRejectedException $exception) {
            throw new WooCommerceUrlException($exception->getMessage(), previous: $exception);
        }
    }
}
