<?php

namespace Tests\Feature\Concerns;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Enums\WhatsAppFlowStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MercadoPagoConfig;
use App\Models\Message;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppCheckout;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WooCommerceConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Tenant con WhatsApp, WooCommerce, Mercado Pago y el formulario de compra
 * instalado, más un producto variable (talle 2 = variación 3983).
 */
trait WhatsAppCheckoutFixtures
{
    // IP pública literal: PublicUrlGuard no necesita DNS real.
    protected const STORE_URL = 'https://93.184.216.34';

    protected const MP_WEBHOOK_TOKEN = 'mp-webhook-token';

    protected Tenant $tenant;

    protected User $user;

    protected WhatsAppConfig $waConfig;

    protected Conversation $conversation;

    protected WhatsAppFlow $flow;

    protected Product $product;

    protected ProductVariant $variant;

    protected ?string $flowsPublicPem = null;

    protected function setUpCheckoutWorld(bool $withFlowKeys = false): void
    {
        $privatePem = null;
        if ($withFlowKeys) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $privatePem);
            $this->flowsPublicPem = openssl_pkey_get_details($key)['key'];
        }

        $this->tenant = Tenant::create(['name' => 'Acme']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::ADMIN]);
        $this->waConfig = WhatsAppConfig::create([
            'phone_number_id' => '123456789',
            'display_phone_number' => '+54 9 11 0000-0000',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
            ...($withFlowKeys ? [
                'flows_private_key' => Crypt::encryptString($privatePem),
                'flows_public_key' => $this->flowsPublicPem,
                'flows_public_key_uploaded_at' => now(),
                'flows_endpoint_token' => 'endpoint-token',
            ] : []),
        ]);
        $channel = Channel::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $this->waConfig->id,
        ]);
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);
        $this->conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $this->openWindow();

        $woo = new WooCommerceConfig([
            'tenant_id' => $this->tenant->id,
            'store_url' => self::STORE_URL,
            'enabled' => true,
            'whatsapp_checkout_enabled' => true,
            'meta_catalog_id' => 'catalog-1',
        ]);
        $woo->setEncryptedConsumerKey('ck_test');
        $woo->setEncryptedConsumerSecret('cs_test');
        $woo->save();

        $mp = new MercadoPagoConfig(['tenant_id' => $this->tenant->id, 'webhook_token' => self::MP_WEBHOOK_TOKEN]);
        $mp->setEncryptedAccessToken('APP_USR-test');
        $mp->save();

        $this->flow = WhatsAppFlow::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_config_id' => $this->waConfig->id,
            'meta_flow_id' => 'flow-checkout',
            'name' => 'Compra por WhatsApp',
            'status' => WhatsAppFlowStatus::Published,
            'categories' => ['SHOPPING'],
            'data_api_version' => '3.0',
            'flow_json' => file_get_contents(resource_path('flows/whatsapp-checkout.json')),
            'system_key' => WhatsAppFlow::SYSTEM_CHECKOUT,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Conjunto bebé futbol boca',
            'price' => 24300,
            'is_active' => true,
            'source' => 'woocommerce',
            'external_id' => '3980',
            'external_type' => 'variable',
        ]);
        $this->variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'external_id' => '3983',
            'attribute_values' => ['Talle' => '2'],
            'price' => 24300,
            'stock_status' => 'instock',
        ]);
    }

    protected function openWindow(): void
    {
        Message::create([
            'tenant_id' => $this->conversation->tenant_id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $this->conversation->contact_id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'in.'.uniqid(),
        ]);
    }

    protected function makeSession(): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_flow_id' => $this->flow->id,
            'conversation_id' => $this->conversation->id,
            'contact_id' => $this->conversation->contact_id,
            'flow_token' => 'token-'.uniqid(),
            'status' => WhatsAppFlowSession::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);
    }

    protected function makeCheckout(array $attributes = []): WhatsAppCheckout
    {
        return WhatsAppCheckout::create($attributes + [
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'contact_id' => $this->conversation->contact_id,
            'whatsapp_flow_session_id' => $this->makeSession()->id,
            'status' => WhatsAppCheckout::CART,
            'items' => [['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 1]],
        ]);
    }

    /** Checkout listo para pagar: cotizado, con envío elegido y pedido #1001 en Woo. */
    protected function quotedAttributes(): array
    {
        return [
            'quote' => $this->quoteArray(),
            'cart_token' => 'cart-token-1',
            'shipping_address' => [
                'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '1122334455', 'email' => 'jane@example.com',
                'street' => 'Corrientes', 'street_number' => '1234', 'apartment' => '2B', 'city' => 'CABA',
                'postal_code' => '1043', 'state' => 'C', 'state_text' => '', 'country' => 'AR',
            ],
            'shipping_rate' => $this->quoteArray()['rates'][0],
            'email' => 'jane@example.com',
            'total' => 34696,
        ];
    }

    protected function quoteArray(): array
    {
        return [
            'currency' => 'ARS',
            'minor' => 2,
            'items' => [['woo_id' => 3983, 'name' => 'Conjunto bebé futbol boca - 2', 'quantity' => 1, 'unit_price' => 24300.0, 'line_total' => 24300.0]],
            'subtotal' => 24300.0,
            'discount' => 0.0,
            'rates' => [[
                'id' => 'correoargentino_shipping_method:5',
                'label' => 'Correo Argentino clásico a domicilio',
                'delivery_time' => '3 a 6 días hábiles',
                'cost' => 10396.0,
                'method_id' => 'correoargentino_shipping_method',
                'instance_id' => 5,
                'meta_data' => [['key' => 'tipo', 'value' => 'clasico']],
            ]],
            'coupon' => null,
            'couponError' => null,
            'cart_token' => 'cart-token-1',
        ];
    }

    /** Cuerpo de carrito de la Store API (precios en centavos). */
    protected function storeCart(array $coupons = [], int $discount = 0): array
    {
        return [
            'items' => [['id' => 3983, 'name' => 'Conjunto bebé futbol boca - 2', 'quantity' => 1, 'prices' => ['price' => '2430000'], 'totals' => ['line_total' => '2430000']]],
            'coupons' => $coupons,
            'totals' => [
                'currency_code' => 'ARS',
                'currency_minor_unit' => 2,
                'total_items' => '2430000',
                'total_discount' => (string) $discount,
                'total_discount_tax' => '0',
            ],
            'shipping_rates' => [['shipping_rates' => [
                ['rate_id' => 'correoargentino_shipping_method:6', 'name' => 'Correo Argentino expreso a domicilio', 'delivery_time' => '1 a 3 días hábiles', 'price' => '1500000', 'taxes' => '0', 'method_id' => 'correoargentino_shipping_method', 'instance_id' => 6, 'meta_data' => []],
                ['rate_id' => 'correoargentino_shipping_method:5', 'name' => 'Correo Argentino clásico a domicilio', 'delivery_time' => '3 a 6 días hábiles', 'price' => '1039600', 'taxes' => '0', 'method_id' => 'correoargentino_shipping_method', 'instance_id' => 5, 'meta_data' => []],
            ]]],
        ];
    }

    /** Estado del pedido en Woo que devuelve GET /orders/{id}. */
    protected string $wooOrderStatus = 'on-hold';

    protected string $wooOrderTotal = '34696.00';

    protected int $mpPreferenceStatus = 201;

    /** Pago que devuelve GET /v1/payments/{id}. */
    protected array $mpPayment = [];

    /** Resultados de GET /v1/payments/search. */
    protected array $mpSearch = [];

    /** Si es true, Meta responde a los mensajes de catálogo/productos que no los encuentra (131009). */
    protected bool $catalogProductsMissing = false;

    /** @var list<string> Retailer ids que Meta todavía no habilitó: los rechaza de a uno, como en producción. */
    protected array $missingRetailerIds = [];

    /** Primer retailer id del mensaje (fichas o miniatura del catálogo) que Meta no tiene habilitado. */
    private function firstMissingRetailerId(Request $request): ?string
    {
        $interactive = $request['interactive'] ?? [];
        $ids = match ($interactive['type'] ?? null) {
            'product' => [$interactive['action']['product_retailer_id'] ?? null],
            'catalog_message' => [$interactive['action']['parameters']['thumbnail_product_retailer_id'] ?? null],
            default => array_column($interactive['action']['sections'][0]['product_items'] ?? [], 'product_retailer_id'),
        };

        return array_values(array_intersect($ids, $this->missingRetailerIds))[0] ?? null;
    }

    /** WhatsApp Cloud API, Woo REST y Mercado Pago simulados. */
    protected function fakeCommerce(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();

            return match (true) {
                $this->catalogProductsMissing && str_contains($url, 'graph.facebook.com') && in_array($request['interactive']['type'] ?? null, ['catalog_message', 'product', 'product_list'], true) => Http::response(['error' => [
                    'message' => '(#131009) Parameter value is not valid',
                    'code' => 131009,
                    'error_data' => ['messaging_product' => 'whatsapp', 'details' => 'Products not found in FB catalog'],
                ]], 400),
                str_contains($url, 'graph.facebook.com') && ($missing = $this->firstMissingRetailerId($request)) !== null => Http::response(['error' => [
                    'message' => '(#131009) Parameter value is not valid',
                    'code' => 131009,
                    'error_data' => ['messaging_product' => 'whatsapp', 'details' => "product not found for product_retailer_id, {$missing}, in catalog_id, catalog-1"],
                ]], 400),
                str_contains($url, 'graph.facebook.com') => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]),
                $method === 'POST' && str_ends_with($url, '/wc/v3/orders') => Http::response(['id' => 501, 'number' => '1001', 'total' => $this->wooOrderTotal, 'status' => 'on-hold'], 201),
                $method === 'GET' && (bool) preg_match('#/wc/v3/orders/\d+$#', $url) => Http::response(['id' => 501, 'number' => '1001', 'status' => $this->wooOrderStatus]),
                $method === 'PUT' && (bool) preg_match('#/wc/v3/orders/\d+$#', $url) => Http::response(['id' => 501, 'number' => '1001', 'status' => $request['status'] ?? $this->wooOrderStatus]),
                str_contains($url, '/wc/v3/settings/general') => Http::response([['id' => 'woocommerce_default_country', 'value' => 'AR:C']]),
                $method === 'POST' && str_ends_with($url, 'api.mercadopago.com/checkout/preferences') => $this->mpPreferenceStatus < 300
                    ? Http::response(['id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-1'], $this->mpPreferenceStatus)
                    : Http::response(['message' => 'error'], $this->mpPreferenceStatus),
                $method === 'PUT' && str_contains($url, 'api.mercadopago.com/checkout/preferences/') => Http::response(['id' => 'pref-1']),
                str_contains($url, 'api.mercadopago.com/v1/payments/search') => Http::response(['results' => $this->mpSearch]),
                str_contains($url, 'api.mercadopago.com/v1/payments/') => Http::response($this->mpPayment),
                default => Http::response(['message' => "unexpected {$method} {$url}"], 500),
            };
        });
    }

    /** Checkout esperando pago del pedido #1001 (id 501 en Woo). */
    protected function awaitingPaymentCheckout(array $attributes = []): WhatsAppCheckout
    {
        return $this->makeCheckout($attributes + [
            'status' => WhatsAppCheckout::AWAITING_PAYMENT,
            'external_order_id' => 501,
            'external_order_number' => '1001',
            'payment_preference_id' => 'pref-1',
            'payment_url' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-1',
            'payment_expires_at' => now()->addHours(2),
        ] + $this->quotedAttributes());
    }

    /** Requests salientes por método y sufijo de URL. */
    protected function sentTo(string $method, string $pattern): Collection
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method && (bool) preg_match($pattern, $r->url()))
            ->map(fn (array $pair) => $pair[0])
            ->values();
    }
}
