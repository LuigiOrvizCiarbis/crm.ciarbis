<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WhatsAppCheckout;
use App\Models\WooCommerceConfig;
use App\Services\WooCommerceService;
use App\Services\WooCommerceSyncException;
use App\Services\WooCommerceUrlException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Escritura y cotización contra WooCommerce para la compra por WhatsApp:
 * Store API (wc/store/v1) para cotizar envíos y cupones, REST (wc/v3) para
 * crear y actualizar pedidos. Solo APIs estándar de WooCommerce.
 */
class WooCommerceCheckoutGateway
{
    private const REST_TIMEOUT = 8;

    private const STORE_TIMEOUT = 7;

    private const MAX_BATCH_REQUESTS = 25;

    private const MAX_LINES = 23;

    private const UNREACHABLE = 'No pudimos comunicarnos con la tienda. Probá de nuevo en un rato.';

    private const STORE_PATH = '/wc/store/v1';

    public function __construct(private readonly WooCommerceService $woo) {}

    /**
     * Cotiza el carrito (productos, cupón y envío al destino) en un carrito
     * nuevo de la Store API.
     *
     * @param  list<array{woo_id: int, quantity: int}>  $lines
     * @param  ?array{country: string, state: ?string, postcode: string, city: string, address_1: string}  $destination
     * @return array<string, mixed>
     *
     * @throws CheckoutException
     */
    public function quote(WooCommerceConfig $config, array $lines, ?array $destination, ?string $coupon): array
    {
        if (count($lines) > self::MAX_LINES) {
            throw new CheckoutException('Demasiados productos distintos en un pedido.');
        }

        $response = $this->send(fn () => $this->store($config)->get('/cart'));
        $token = $response->header('Cart-Token');
        if (! $response->successful() || $token === '') {
            throw new CheckoutException('No pudimos cotizar con la tienda.');
        }

        $requests = [];
        foreach ($lines as $line) {
            $requests[] = $this->batchRequest('/cart/add-item', ['id' => (int) $line['woo_id'], 'quantity' => (int) $line['quantity']]);
        }
        if ($coupon !== null && $coupon !== '') {
            $requests[] = $this->batchRequest('/cart/apply-coupon', ['code' => $coupon]);
        }
        if ($destination !== null) {
            $requests[] = $this->batchRequest('/cart/update-customer', ['shipping_address' => $destination]);
        }

        $batch = $this->batch($config, $token, $requests);
        if (! $batch->successful()) {
            $this->failStoreResponse($batch);
        }

        $cart = null;
        $couponError = null;
        foreach ($this->batchResponses($batch) as $index => $item) {
            $path = $requests[$index]['path'] ?? '';
            $status = (int) ($item['status'] ?? 500);
            $body = is_array($item['body'] ?? null) ? $item['body'] : [];

            if ($status >= 200 && $status < 300) {
                if (isset($body['totals'])) {
                    $cart = $body;
                }

                continue;
            }

            $message = $this->plainMessage($body['message'] ?? null);
            if (str_ends_with($path, '/apply-coupon')) {
                $couponError = $message ?: 'El código de descuento no es válido.';

                continue;
            }

            throw new CheckoutException($message ?: 'No pudimos cotizar con la tienda.');
        }

        if ($cart === null) {
            throw new CheckoutException('No pudimos cotizar con la tienda.');
        }

        return $this->shape($cart, $couponError, $token);
    }

    /**
     * Cambia el cupón de un carrito ya cotizado, sin repetir la cotización de
     * envío. Null si el carrito ya no sirve (token vencido): el llamador cotiza
     * de nuevo.
     *
     * @return ?array<string, mixed>
     *
     * @throws CheckoutException
     */
    public function applyCoupon(WooCommerceConfig $config, string $cartToken, ?string $previous, ?string $coupon): ?array
    {
        $requests = [];
        if ($previous !== null && $previous !== '') {
            $requests[] = $this->batchRequest('/cart/remove-coupon', ['code' => $previous]);
        }
        if ($coupon !== null && $coupon !== '') {
            $requests[] = $this->batchRequest('/cart/apply-coupon', ['code' => $coupon]);
        }
        if ($requests === []) {
            return null;
        }

        $batch = $this->batch($config, $cartToken, $requests);
        if (in_array($batch->status(), [401, 403, 404], true)) {
            return null;
        }
        if (! $batch->successful()) {
            $this->failStoreResponse($batch);
        }

        $cart = null;
        $couponError = null;
        foreach ($this->batchResponses($batch) as $index => $item) {
            $path = $requests[$index]['path'] ?? '';
            $status = (int) ($item['status'] ?? 500);
            $body = is_array($item['body'] ?? null) ? $item['body'] : [];

            if ($status >= 200 && $status < 300) {
                if (isset($body['totals'])) {
                    $cart = $body;
                }

                continue;
            }

            if (in_array($status, [401, 403, 404], true)) {
                return null;
            }

            if (str_ends_with($path, '/apply-coupon')) {
                $couponError = $this->plainMessage($body['message'] ?? null) ?: 'El código de descuento no es válido.';
            }
        }

        if ($cart === null || empty($cart['items'])) {
            return null;
        }

        return $this->shape($cart, $couponError, $cartToken);
    }

    /**
     * Crea el pedido en on-hold (reserva el stock) con la dirección, el envío y
     * el cupón del checkout.
     *
     * @return array{id: int, number: string, total: float}
     *
     * @throws CheckoutException
     */
    public function createOrder(WooCommerceConfig $config, WhatsAppCheckout $checkout): array
    {
        $address = (array) ($checkout->shipping_address ?? []);
        $country = (string) ($address['country'] ?? $this->defaultCountry($config));
        $state = (string) (($address['state'] ?? '') !== '' ? $address['state'] : ($address['state_text'] ?? ''));

        $person = [
            'first_name' => (string) ($address['first_name'] ?? ''),
            'last_name' => (string) ($address['last_name'] ?? ''),
            'address_1' => trim(($address['street'] ?? '').' '.($address['street_number'] ?? '')),
            'address_2' => (string) ($address['apartment'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'state' => $state,
            'postcode' => (string) ($address['postal_code'] ?? ''),
            'country' => $country,
            'phone' => (string) ($address['phone'] ?? ''),
        ];

        $rate = (array) ($checkout->shipping_rate ?? []);

        $payload = [
            'status' => 'on-hold',
            'set_paid' => false,
            'payment_method' => 'crm-mercadopago',
            'payment_method_title' => 'Mercado Pago (WhatsApp)',
            'billing' => $person + ['email' => (string) ($checkout->email ?? '')],
            'shipping' => $person,
            'line_items' => $this->lineItems($checkout),
            'shipping_lines' => [[
                'method_id' => (string) ($rate['method_id'] ?? ''),
                'instance_id' => (string) ($rate['instance_id'] ?? ''),
                'method_title' => (string) ($rate['label'] ?? ''),
                'total' => number_format((float) ($rate['cost'] ?? 0), 2, '.', ''),
                'meta_data' => $this->metaData($rate['meta_data'] ?? []),
            ]],
            'meta_data' => [
                ['key' => 'crm_checkout_id', 'value' => (string) $checkout->id],
                ['key' => 'sales_channel', 'value' => 'whatsapp'],
                ['key' => 'crm_street_name', 'value' => (string) ($address['street'] ?? '')],
                ['key' => 'crm_street_number', 'value' => (string) ($address['street_number'] ?? '')],
            ],
        ];

        if (! empty($checkout->coupon_code)) {
            $payload['coupon_lines'] = [['code' => $checkout->coupon_code]];
        }

        $response = $this->send(fn () => $this->rest($config)->post('/orders', $payload));
        $this->assertRest($response);

        $order = (array) $response->json();
        $id = (int) ($order['id'] ?? 0);
        if ($id === 0) {
            throw new CheckoutException(self::UNREACHABLE);
        }

        $total = (float) ($order['total'] ?? 0);
        if (abs($total - (float) $checkout->total) > 1) {
            try {
                $this->updateOrder($config, $id, ['status' => 'cancelled']);
            } catch (CheckoutException $e) {
                Log::warning('No se pudo cancelar el pedido de WooCommerce con total distinto', [
                    'checkout_id' => $checkout->id,
                    'order_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }

            throw new CheckoutException('El total cambió. Volvé a revisar el envío.');
        }

        return [
            'id' => $id,
            'number' => (string) ($order['number'] ?? $id),
            'total' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws CheckoutException
     */
    public function updateOrder(WooCommerceConfig $config, int $orderId, array $data): array
    {
        $response = $this->send(fn () => $this->rest($config)->put("/orders/{$orderId}", $data));
        $this->assertRest($response);

        return (array) $response->json();
    }

    /**
     * Cancela el pedido si sigue sin pagar (Woo devuelve el stock).
     *
     * @throws CheckoutException
     */
    public function cancelIfUnpaid(WooCommerceConfig $config, int $orderId): bool
    {
        $response = $this->send(fn () => $this->rest($config)->get("/orders/{$orderId}"));
        $this->assertRest($response);

        if (! in_array($response->json('status'), ['pending', 'on-hold'], true)) {
            return false;
        }

        $this->updateOrder($config, $orderId, ['status' => 'cancelled']);

        return true;
    }

    public function orderAdminUrl(WooCommerceConfig $config, int $orderId): string
    {
        return rtrim($config->store_url, '/')."/wp-admin/post.php?post={$orderId}&action=edit";
    }

    /**
     * Provincias/estados del país según la tienda (caché de 1 día).
     *
     * @return list<array{id: string, title: string}>
     *
     * @throws CheckoutException
     */
    public function states(WooCommerceConfig $config, string $country): array
    {
        $country = strtoupper($country);
        $key = "woocommerce-checkout:states:{$config->tenant_id}:{$country}";

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->send(fn () => $this->rest($config)->get('/data/countries/'.rawurlencode($country)));
        if ($response->status() === 404) {
            $states = [];
        } else {
            $this->assertRest($response);
            $states = array_values(array_map(
                fn (array $state) => ['id' => (string) ($state['code'] ?? ''), 'title' => (string) ($state['name'] ?? '')],
                array_filter((array) $response->json('states', []), 'is_array'),
            ));
        }

        Cache::put($key, $states, now()->addDay());

        return $states;
    }

    /**
     * País por defecto de la tienda (woocommerce_default_country), caché de 1
     * día. Ante cualquier falla, AR.
     */
    public function defaultCountry(WooCommerceConfig $config): string
    {
        $key = "woocommerce-checkout:default-country:{$config->tenant_id}";

        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->rest($config)->get('/settings/general');
        } catch (\Throwable $e) {
            return 'AR';
        }

        if (! $response->successful()) {
            return 'AR';
        }

        foreach ((array) $response->json() as $setting) {
            if (is_array($setting) && ($setting['id'] ?? null) === 'woocommerce_default_country') {
                $country = strtoupper(explode(':', (string) ($setting['value'] ?? ''))[0]);
                if ($country !== '') {
                    Cache::put($key, $country, now()->addDay());

                    return $country;
                }
            }
        }

        return 'AR';
    }

    /**
     * @return list<array<string, int>>
     *
     * @throws CheckoutException
     */
    private function lineItems(WhatsAppCheckout $checkout): array
    {
        $items = (array) ($checkout->items ?? []);
        $products = Product::withoutGlobalScopes()
            ->where('tenant_id', $checkout->tenant_id)
            ->whereIn('id', array_column($items, 'product_id'))
            ->get()->keyBy('id');
        $variants = ProductVariant::withoutGlobalScopes()
            ->whereIn('id', array_filter(array_column($items, 'variant_id')))
            ->get()->keyBy('id');

        $lines = [];
        foreach ($items as $item) {
            $product = $products->get($item['product_id'] ?? null);
            if (! $product || ! is_numeric($product->external_id)) {
                throw new CheckoutException('Uno de los productos ya no está disponible.');
            }

            $line = ['product_id' => (int) $product->external_id, 'quantity' => (int) $item['quantity']];

            if (! empty($item['variant_id'])) {
                $variant = $variants->get($item['variant_id']);
                if (! $variant || (int) $variant->product_id !== (int) $product->id || ! is_numeric($variant->external_id)) {
                    throw new CheckoutException('Uno de los productos ya no está disponible.');
                }
                $line['variation_id'] = (int) $variant->external_id;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Normaliza el meta_data de la tarifa a la forma que acepta la REST API.
     *
     * @return list<array{key: string, value: mixed}>
     */
    private function metaData(mixed $meta): array
    {
        if (! is_array($meta)) {
            return [];
        }

        $result = [];
        foreach ($meta as $key => $entry) {
            if (is_array($entry) && isset($entry['key'])) {
                $result[] = ['key' => (string) $entry['key'], 'value' => $entry['value'] ?? ''];
            } elseif (is_string($key)) {
                $result[] = ['key' => $key, 'value' => $entry];
            }
        }

        return $result;
    }

    /**
     * Forma pública de la cotización a partir del carrito de la Store API.
     *
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    private function shape(array $cart, ?string $couponError, string $token): array
    {
        $totals = (array) ($cart['totals'] ?? []);
        $minor = (int) ($totals['currency_minor_unit'] ?? 2);
        $money = fn ($value) => (float) ((int) ($value ?? 0)) / (10 ** $minor);

        $items = [];
        foreach ((array) ($cart['items'] ?? []) as $item) {
            $items[] = [
                'woo_id' => (int) ($item['id'] ?? 0),
                'name' => $this->plainMessage($item['name'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'unit_price' => $money($item['prices']['price'] ?? 0),
                'line_total' => $money($item['totals']['line_total'] ?? 0),
            ];
        }

        $rates = [];
        foreach ((array) ($cart['shipping_rates'][0]['shipping_rates'] ?? []) as $rate) {
            $rates[] = [
                'id' => (string) ($rate['rate_id'] ?? ''),
                'label' => $this->plainMessage($rate['name'] ?? ''),
                'delivery_time' => $this->plainMessage($rate['delivery_time'] ?? ''),
                'cost' => $money((int) ($rate['price'] ?? 0) + (int) ($rate['taxes'] ?? 0)),
                'method_id' => (string) ($rate['method_id'] ?? ''),
                'instance_id' => (int) ($rate['instance_id'] ?? 0),
                'meta_data' => $this->metaData($rate['meta_data'] ?? []),
            ];
        }
        usort($rates, fn (array $a, array $b) => $a['cost'] <=> $b['cost']);

        $coupon = null;
        $applied = $cart['coupons'][0] ?? null;
        if (is_array($applied)) {
            $coupon = [
                'code' => (string) ($applied['code'] ?? ''),
                'discount' => $money((int) ($applied['totals']['total_discount'] ?? 0) + (int) ($applied['totals']['total_discount_tax'] ?? 0)),
            ];
        }

        return [
            'currency' => (string) ($totals['currency_code'] ?? ''),
            'minor' => $minor,
            'items' => $items,
            'subtotal' => $money($totals['total_items'] ?? 0),
            'discount' => $money((int) ($totals['total_discount'] ?? 0) + (int) ($totals['total_discount_tax'] ?? 0)),
            'rates' => $rates,
            'coupon' => $coupon,
            'couponError' => $couponError,
            'cart_token' => $token,
        ];
    }

    public function plainMessage(mixed $message): string
    {
        if (! is_string($message)) {
            return '';
        }

        return trim(html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{method: string, path: string, body: array<string, mixed>}
     */
    private function batchRequest(string $path, array $body): array
    {
        return ['method' => 'POST', 'path' => self::STORE_PATH.$path, 'body' => $body];
    }

    /**
     * @param  list<array<string, mixed>>  $requests
     *
     * @throws CheckoutException
     */
    private function batch(WooCommerceConfig $config, string $token, array $requests): Response
    {
        if (count($requests) > self::MAX_BATCH_REQUESTS) {
            throw new CheckoutException('Demasiados productos distintos en un pedido.');
        }

        return $this->send(fn () => $this->store($config)
            ->withHeaders(['Cart-Token' => $token])
            ->post('/batch', ['requests' => $requests]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function batchResponses(Response $batch): array
    {
        return array_values(array_filter((array) $batch->json('responses', []), 'is_array'));
    }

    /**
     * @throws CheckoutException
     */
    private function failStoreResponse(Response $response): never
    {
        if ($response->serverError()) {
            Log::warning('WooCommerce Store API respondió con error', ['status' => $response->status()]);

            throw new CheckoutException(self::UNREACHABLE);
        }

        throw new CheckoutException($this->plainMessage($response->json('message')) ?: 'No pudimos cotizar con la tienda.');
    }

    /**
     * @throws CheckoutException
     */
    private function assertRest(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        if (in_array($status, [401, 403], true)) {
            throw new CheckoutException('La clave de WooCommerce necesita permisos de Lectura/Escritura.');
        }

        if ($response->serverError()) {
            Log::warning('WooCommerce REST respondió con error', ['status' => $status]);

            throw new CheckoutException(self::UNREACHABLE);
        }

        throw new CheckoutException($this->plainMessage($response->json('message')) ?: self::UNREACHABLE);
    }

    /**
     * Ejecuta un request traduciendo fallas de red/URL a CheckoutException.
     *
     * @param  callable(): Response  $call
     *
     * @throws CheckoutException
     */
    private function send(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException|WooCommerceUrlException $e) {
            Log::warning('No se pudo contactar a WooCommerce', ['error' => $e->getMessage()]);

            throw new CheckoutException(self::UNREACHABLE, previous: $e);
        } catch (WooCommerceSyncException $e) {
            throw new CheckoutException($e->getMessage(), previous: $e);
        }
    }

    private function rest(WooCommerceConfig $config): PendingRequest
    {
        return $this->woo->client($config)->timeout(self::REST_TIMEOUT);
    }

    private function store(WooCommerceConfig $config): PendingRequest
    {
        return $this->woo->storeApiClient($config)->timeout(self::STORE_TIMEOUT);
    }
}
