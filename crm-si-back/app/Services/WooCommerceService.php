<?php

namespace App\Services;

use App\Models\WooCommerceConfig;
use App\Support\PublicUrlGuard;
use App\Support\PublicUrlRejectedException;
use App\Support\UnresolvableHostException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Conector con la REST API de WooCommerce (wc/v3): prueba credenciales y lee
 * productos y variaciones. La escritura al catálogo local la hace
 * WooCommerceCatalogWriter; la orquestación del sync, WooCommerceCatalogSync.
 */
class WooCommerceService
{
    private const API_PREFIX = '/wp-json/wc/v3';

    // Página chica a propósito: cada producto variable suma un request de
    // variaciones y la página entera tiene que entrar en el timeout del job.
    public const PRODUCTS_PER_PAGE = 25;

    private const VARIATIONS_PER_PAGE = 100;

    private const MAX_VARIATION_PAGES = 20;

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
     * Cliente autenticado contra la tienda del config. Se arma una vez por
     * página de sync para validar la URL (anti-SSRF) una sola vez.
     *
     * @throws WooCommerceUrlException si la store_url no es una URL pública válida.
     * @throws WooCommerceSyncException si faltan credenciales.
     */
    public function client(WooCommerceConfig $config): PendingRequest
    {
        $key = $config->getDecryptedConsumerKey();
        $secret = $config->getDecryptedConsumerSecret();

        if (! $key || ! $secret) {
            throw new WooCommerceSyncException('Configurá y guardá las credenciales de WooCommerce antes de sincronizar.');
        }

        return $this->request($config->store_url, $key, $secret);
    }

    /**
     * Una página de productos publicados.
     *
     * @return array{items: list<array<string, mixed>>, total_pages: ?int}
     *
     * @throws WooCommerceSyncException
     */
    public function fetchProductsPage(PendingRequest $client, int $page): array
    {
        $response = $client->get('/products', [
            'per_page' => self::PRODUCTS_PER_PAGE,
            'page' => $page,
            'status' => 'publish',
        ]);
        $this->assertSuccessful($response, "los productos (página {$page})");

        return [
            'items' => $this->items($response, "los productos (página {$page})"),
            'total_pages' => $this->totalPages($response),
        ];
    }

    /**
     * Todas las variaciones de un producto variable.
     *
     * @return list<array<string, mixed>>
     *
     * @throws WooCommerceSyncException
     */
    public function fetchVariations(PendingRequest $client, int $productId): array
    {
        $variations = [];

        for ($page = 1; $page <= self::MAX_VARIATION_PAGES; $page++) {
            $response = $client->get("/products/{$productId}/variations", [
                'per_page' => self::VARIATIONS_PER_PAGE,
                'page' => $page,
            ]);
            $this->assertSuccessful($response, "las variaciones del producto {$productId}");

            $items = $this->items($response, "las variaciones del producto {$productId}");
            array_push($variations, ...$items);

            $totalPages = $this->totalPages($response);
            $isLastPage = $totalPages !== null ? $page >= $totalPages : count($items) < self::VARIATIONS_PER_PAGE;
            if ($items === [] || $isLastPage) {
                break;
            }
        }

        return $variations;
    }

    /**
     * @throws WooCommerceSyncException
     */
    private function assertSuccessful(Response $response, string $resource): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        throw new WooCommerceSyncException(in_array($status, [401, 403], true)
            ? "WooCommerce rechazó las credenciales al leer {$resource}."
            : "WooCommerce respondió con un error ({$status}) al leer {$resource}.");
    }

    /**
     * Un 200 que no es una lista JSON (HTML de WordPress sin permalinks, una
     * página de caché o de mantenimiento) no es "no hay productos": tomarlo
     * así desactivaría todo el catálogo y borraría las variantes.
     *
     * @return list<array<string, mixed>>
     *
     * @throws WooCommerceSyncException
     */
    private function items(Response $response, string $resource): array
    {
        $items = $response->json();

        if (! is_array($items) || ! array_is_list($items)) {
            throw new WooCommerceSyncException("WooCommerce no devolvió una lista válida al leer {$resource}. Revisá que la API REST esté accesible (enlaces permanentes, caché o modo mantenimiento).");
        }

        return array_values(array_filter($items, 'is_array'));
    }

    private function totalPages(Response $response): ?int
    {
        $header = $response->header('X-WP-TotalPages');

        return is_numeric($header) ? (int) $header : null;
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
            throw new WooCommerceUrlException(
                $exception->getMessage(),
                retryable: $exception instanceof UnresolvableHostException,
                previous: $exception,
            );
        }
    }
}
