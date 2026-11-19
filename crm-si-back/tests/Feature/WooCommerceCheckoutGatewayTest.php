<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WhatsAppCheckout;
use App\Models\WooCommerceConfig;
use App\Services\Commerce\CheckoutException;
use App\Services\Commerce\WooCommerceCheckoutGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooCommerceCheckoutGatewayTest extends TestCase
{
    use RefreshDatabase;

    // IP pública literal: PublicUrlGuard no necesita DNS real.
    private const STORE_URL = 'https://93.184.216.34';

    private WooCommerceConfig $config;

    private WooCommerceCheckoutGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->createTenantWithRoles();
        $this->config = new WooCommerceConfig(['tenant_id' => $tenant->id, 'store_url' => self::STORE_URL, 'enabled' => true]);
        $this->config->setEncryptedConsumerKey('ck_test');
        $this->config->setEncryptedConsumerSecret('cs_test');
        $this->config->save();

        $this->gateway = app(WooCommerceCheckoutGateway::class);
    }

    public function test_quote_parses_batch_with_cart_token_and_sorted_rates(): void
    {
        Http::fake([
            self::STORE_URL.'/wp-json/wc/store/v1/cart' => Http::response($this->cart(), 200, ['Cart-Token' => 'tok-1']),
            self::STORE_URL.'/wp-json/wc/store/v1/batch' => Http::response(['responses' => [
                ['status' => 201, 'body' => $this->cart()],
                ['status' => 200, 'body' => $this->cart()],
            ]]),
        ]);

        $quote = $this->gateway->quote($this->config, [['woo_id' => 3983, 'quantity' => 1]], [
            'country' => 'AR', 'state' => 'C', 'postcode' => '1414', 'city' => 'CABA', 'address_1' => 'Corrientes 1234',
        ], null);

        $this->assertSame('tok-1', $quote['cart_token']);
        $this->assertSame('ARS', $quote['currency']);
        $this->assertSame(24300.0, $quote['subtotal']);
        $this->assertSame(3983, $quote['items'][0]['woo_id']);
        $this->assertSame(['clasico', 'expreso'], array_column($quote['rates'], 'label'));
        $this->assertSame(10396.0, $quote['rates'][0]['cost']);
        $this->assertNull($quote['couponError']);

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/batch')) {
                return false;
            }

            $paths = array_column($request['requests'], 'path');

            return $request->hasHeader('Cart-Token', 'tok-1')
                && $request->hasHeader('X-CRM-Checkout', '1')
                && $paths === ['/wc/store/v1/cart/add-item', '/wc/store/v1/cart/update-customer'];
        });
    }

    public function test_quote_add_item_failure_throws_plain_message(): void
    {
        Http::fake([
            self::STORE_URL.'/wp-json/wc/store/v1/cart' => Http::response($this->cart(), 200, ['Cart-Token' => 'tok-1']),
            self::STORE_URL.'/wp-json/wc/store/v1/batch' => Http::response(['responses' => [
                ['status' => 400, 'body' => ['code' => 'woocommerce_rest_product_partially_out_of_stock', 'message' => 'No hay suficiente stock de &quot;<strong>Conjunto</strong>&quot;']],
            ]]),
        ]);

        try {
            $this->gateway->quote($this->config, [['woo_id' => 3983, 'quantity' => 5]], null, null);
            $this->fail('Se esperaba CheckoutException');
        } catch (CheckoutException $e) {
            $this->assertSame('No hay suficiente stock de "Conjunto"', $e->getMessage());
        }
    }

    public function test_quote_coupon_error_is_not_fatal(): void
    {
        Http::fake([
            self::STORE_URL.'/wp-json/wc/store/v1/cart' => Http::response($this->cart(), 200, ['Cart-Token' => 'tok-1']),
            self::STORE_URL.'/wp-json/wc/store/v1/batch' => Http::response(['responses' => [
                ['status' => 201, 'body' => $this->cart()],
                ['status' => 400, 'body' => ['message' => 'El cupón &quot;x&quot; no existe.']],
            ]]),
        ]);

        $quote = $this->gateway->quote($this->config, [['woo_id' => 3983, 'quantity' => 1]], null, 'x');

        $this->assertSame('El cupón "x" no existe.', $quote['couponError']);
        $this->assertCount(2, $quote['rates']);
    }

    public function test_apply_coupon_returns_null_on_forbidden(): void
    {
        Http::fake([
            self::STORE_URL.'/wp-json/wc/store/v1/batch' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $this->assertNull($this->gateway->applyCoupon($this->config, 'tok-1', null, 'promo'));
    }

    public function test_create_order_sends_on_hold_with_variation_and_meta(): void
    {
        $product = Product::create(['tenant_id' => $this->config->tenant_id, 'name' => 'Conjunto', 'source' => 'woocommerce', 'external_id' => '3980']);
        $variant = ProductVariant::create(['tenant_id' => $this->config->tenant_id, 'product_id' => $product->id, 'external_id' => '3983', 'attribute_values' => ['Talle' => '2']]);
        $checkout = $this->checkout([['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]]);

        Http::fake([
            self::STORE_URL.'/wp-json/wc/v3/orders' => Http::response(['id' => 501, 'number' => '501', 'total' => '34696.00'], 201),
        ]);

        $order = $this->gateway->createOrder($this->config, $checkout);

        $this->assertSame(['id' => 501, 'number' => '501', 'total' => 34696.0], $order);
        Http::assertSent(function (Request $request) use ($checkout) {
            $meta = array_column($request['meta_data'], 'value', 'key');

            return $request->method() === 'POST'
                && $request['status'] === 'on-hold'
                && $request['line_items'][0] === ['product_id' => 3980, 'quantity' => 1, 'variation_id' => 3983]
                && $request['shipping_lines'][0]['total'] === '10396.00'
                && $request['shipping_lines'][0]['meta_data'] === [['key' => 'Artículos', 'value' => 'Conjunto × 1']]
                && $request['billing']['address_1'] === 'Corrientes 1234'
                && $meta['crm_checkout_id'] === (string) $checkout->id
                && $meta['sales_channel'] === 'whatsapp';
        });
    }

    public function test_cancel_if_unpaid_cancels_on_hold_order(): void
    {
        Http::fake([
            self::STORE_URL.'/wp-json/wc/v3/orders/501' => Http::sequence()
                ->push(['id' => 501, 'status' => 'on-hold'])
                ->push(['id' => 501, 'status' => 'cancelled']),
        ]);

        $this->assertTrue($this->gateway->cancelIfUnpaid($this->config, 501));
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['status'] === 'cancelled');
    }

    public function test_cancel_if_unpaid_skips_processing_order(): void
    {
        Http::fake([
            self::STORE_URL.'/wp-json/wc/v3/orders/501' => Http::response(['id' => 501, 'status' => 'processing']),
        ]);

        $this->assertFalse($this->gateway->cancelIfUnpaid($this->config, 501));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function checkout(array $items): WhatsAppCheckout
    {
        $tenantId = $this->config->tenant_id;
        $user = User::factory()->create(['tenant_id' => $tenantId, 'role' => UserRole::ADMIN]);
        $channel = Channel::create([
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp', 'status' => 'active',
        ]);
        $contact = Contact::create(['tenant_id' => $tenantId, 'name' => 'Ana Pérez', 'phone' => '5491100000000', 'source' => 'whatsapp']);
        $conversation = Conversation::create([
            'tenant_id' => $tenantId, 'channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => 'open',
        ]);

        return WhatsAppCheckout::withoutGlobalScopes()->create([
            'tenant_id' => $this->config->tenant_id,
            'conversation_id' => $conversation->id,
            'status' => WhatsAppCheckout::PLACING,
            'items' => $items,
            'shipping_address' => [
                'first_name' => 'Ana', 'last_name' => 'Pérez', 'phone' => '5491100000000',
                'street' => 'Corrientes', 'street_number' => '1234', 'apartment' => '3B',
                'city' => 'CABA', 'postal_code' => '1414', 'state' => 'C', 'country' => 'AR',
            ],
            'shipping_rate' => [
                'id' => 'correo:1', 'label' => 'clasico', 'cost' => 10396.0, 'method_id' => 'correoargentino_shipping_method',
                'instance_id' => 1, 'meta_data' => [['key' => 'Artículos', 'value' => 'Conjunto × 1']],
            ],
            'quote' => ['currency' => 'ARS'],
            'total' => 34696,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function cart(): array
    {
        return [
            'items' => [[
                'id' => 3983, 'name' => 'Conjunto', 'quantity' => 1,
                'prices' => ['price' => '2430000'], 'totals' => ['line_total' => '2430000'],
            ]],
            'coupons' => [],
            'shipping_rates' => [[
                'shipping_rates' => [
                    ['rate_id' => 'correo:2', 'name' => 'expreso', 'delivery_time' => '1 a 3 días', 'price' => '1500000', 'taxes' => '0', 'method_id' => 'correoargentino_shipping_method', 'instance_id' => 2, 'meta_data' => []],
                    ['rate_id' => 'correo:1', 'name' => 'clasico', 'delivery_time' => '3 a 6 días', 'price' => '1039600', 'taxes' => '0', 'method_id' => 'correoargentino_shipping_method', 'instance_id' => 1, 'meta_data' => []],
                ],
            ]],
            'totals' => [
                'currency_code' => 'ARS', 'currency_minor_unit' => 2,
                'total_items' => '2430000', 'total_discount' => '0', 'total_discount_tax' => '0',
            ],
        ];
    }
}
