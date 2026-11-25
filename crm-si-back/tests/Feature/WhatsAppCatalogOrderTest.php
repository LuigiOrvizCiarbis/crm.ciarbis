<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Enums\WhatsAppFlowStatus;
use App\Jobs\EvaluateAutomationEventJob;
use App\Jobs\GenerateAiReplyJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MercadoPagoConfig;
use App\Models\Message;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WhatsAppCheckout;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Models\WooCommerceConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppCatalogOrderTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_URL = 'https://93.184.216.34';

    private Conversation $conversation;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([EvaluateAutomationEventJob::class]);
        Queue::fake([GenerateAiReplyJob::class]);

        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $config = WhatsAppConfig::create([
            'phone_number_id' => '123456789',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Ana Pérez',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);
        $this->conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'ai_autoreply_enabled' => true,
        ]);

        $woo = new WooCommerceConfig([
            'tenant_id' => $tenant->id,
            'store_url' => self::STORE_URL,
            'enabled' => true,
            'whatsapp_checkout_enabled' => true,
            'meta_catalog_id' => 'catalog-1',
            'catalog_retailer_id_format' => WooCommerceConfig::RETAILER_ID_PRODUCT_ID,
        ]);
        $woo->setEncryptedConsumerKey('ck_test');
        $woo->setEncryptedConsumerSecret('cs_test');
        $woo->save();

        $mp = new MercadoPagoConfig(['tenant_id' => $tenant->id, 'webhook_token' => 'mp-token']);
        $mp->setEncryptedAccessToken('APP_USR-test');
        $mp->save();

        WhatsAppFlow::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $config->id,
            'meta_flow_id' => 'flow-checkout',
            'name' => 'Compra por WhatsApp',
            'system_key' => WhatsAppFlow::SYSTEM_CHECKOUT,
            'status' => WhatsAppFlowStatus::Published,
            'published_at' => now(),
            'categories' => ['SHOPPING'],
            'flow_json' => file_get_contents(resource_path('flows/whatsapp-checkout.json')),
            'data_api_version' => '3.0',
        ]);

        $this->product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Conjunto bebé futbol boca',
            'price' => 24300,
            'is_active' => true,
            'source' => 'woocommerce',
            'external_id' => '3980',
            'external_type' => 'variable',
        ]);
        $this->variant = ProductVariant::create([
            'tenant_id' => $tenant->id,
            'product_id' => $this->product->id,
            'external_id' => '3983',
            'attribute_values' => ['Talle' => '2'],
            'price' => 24300,
            'stock_status' => 'instock',
        ]);
    }

    public function test_cart_with_known_variant_opens_a_checkout_and_sends_the_flow(): void
    {
        $this->fakeGraph();

        $this->sendOrder([['product_retailer_id' => '3983', 'quantity' => 1, 'item_price' => 24300, 'currency' => 'ARS']]);

        $order = Message::where('external_id', 'wamid.order-1')->sole();
        $this->assertSame('order', $order->message_type->value);
        $this->assertSame('🛒 Carrito: 1 productos', $order->content);
        $this->assertSame('order', $order->interactive['kind']);
        $this->assertSame('catalog-1', $order->interactive['catalog_id']);

        $checkout = WhatsAppCheckout::withoutGlobalScopes()->sole();
        $this->assertSame(WhatsAppCheckout::CART, $checkout->status);
        $this->assertSame(
            [['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 1]],
            $checkout->items,
        );
        $this->assertNotNull($checkout->whatsapp_flow_session_id);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/123456789/messages')
            && $request['type'] === 'interactive'
            && $request['interactive']['type'] === 'flow');
        Queue::assertNotPushed(GenerateAiReplyJob::class);
    }

    public function test_cart_with_unknown_retailer_id_only_sends_the_fallback_text(): void
    {
        $this->fakeGraph();

        $this->sendOrder([['product_retailer_id' => 'desconocido', 'quantity' => 1]]);

        $this->assertSame(0, WhatsAppCheckout::withoutGlobalScopes()->count());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/123456789/messages')
            && $request['type'] === 'text'
            && str_contains($request['text']['body'], 'No pudimos procesar tu carrito'));
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/123456789/messages')
            && $request['type'] === 'interactive');
    }

    public function test_new_cart_replaces_the_previous_unpaid_order(): void
    {
        $old = WhatsAppCheckout::create([
            'tenant_id' => $this->conversation->tenant_id,
            'conversation_id' => $this->conversation->id,
            'contact_id' => $this->conversation->contact_id,
            'status' => WhatsAppCheckout::AWAITING_PAYMENT,
            'items' => [['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 1]],
            'total' => 34696,
            'external_order_id' => 900,
            'external_order_number' => '900',
            'payment_preference_id' => 'pref-1',
            'payment_url' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-1',
            'payment_expires_at' => now()->addHour(),
        ]);

        $this->fakeGraph([
            'https://api.mercadopago.com/v1/payments/search*' => Http::response(['results' => []]),
            'https://api.mercadopago.com/checkout/preferences/pref-1' => Http::response(['id' => 'pref-1']),
            self::STORE_URL.'/wp-json/wc/v3/orders/900' => function (Request $request) {
                return $request->method() === 'GET'
                    ? Http::response(['id' => 900, 'number' => '900', 'status' => 'on-hold'])
                    : Http::response(['id' => 900, 'number' => '900', 'status' => 'cancelled']);
            },
        ]);

        $this->sendOrder([['product_retailer_id' => '3983', 'quantity' => 2]]);

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/wp-json/wc/v3/orders/900')
            && $request['status'] === 'cancelled');

        $this->assertSame(WhatsAppCheckout::CANCELLED, $old->fresh()->status);
        $this->assertNotNull($old->fresh()->closed_at);

        $new = WhatsAppCheckout::withoutGlobalScopes()->where('id', '!=', $old->id)->sole();
        $this->assertSame(WhatsAppCheckout::CART, $new->status);
        $this->assertSame(2, $new->items[0]['quantity']);

        $outbound = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/123456789/messages'))
            ->map(fn (array $pair) => $pair[0]['type'] === 'text' ? $pair[0]['text']['body'] : $pair[0]['interactive']['type'])
            ->values()
            ->all();
        $flowIndex = array_search('flow', $outbound, true);
        $replacedIndex = collect($outbound)->search(fn ($body) => str_starts_with($body, 'Reemplazamos tu pedido #900 por este nuevo.'));
        $this->assertNotFalse($flowIndex);
        $this->assertNotFalse($replacedIndex);
        $this->assertGreaterThan($flowIndex, $replacedIndex);
    }

    /** @param  array<string, mixed>  $extra */
    private function fakeGraph(array $extra = []): void
    {
        Http::fake([
            ...$extra,
            'https://graph.facebook.com/*/123456789/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.out-1']]])
                ->push(['messages' => [['id' => 'wamid.out-2']]])
                ->push(['messages' => [['id' => 'wamid.out-3']]])
                ->push(['messages' => [['id' => 'wamid.out-4']]]),
            'https://graph.facebook.com/*' => Http::response(['success' => true]),
        ]);
    }

    /** @param  list<array<string, mixed>>  $items */
    private function sendOrder(array $items): void
    {
        $this->postJson('/api/whatsapp-webhook', ['entry' => [[
            'id' => 'waba-test',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => '123456789'],
                    'contacts' => [['profile' => ['name' => 'Ana Pérez'], 'wa_id' => '5492235112208']],
                    'messages' => [[
                        'from' => '5492235112208',
                        'id' => 'wamid.order-1',
                        'timestamp' => (string) now()->timestamp,
                        'type' => 'order',
                        'order' => ['catalog_id' => 'catalog-1', 'product_items' => $items],
                    ]],
                ],
            ]],
        ]]])->assertOk();
    }
}
