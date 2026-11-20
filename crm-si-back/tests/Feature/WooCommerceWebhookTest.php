<?php

namespace Tests\Feature;

use App\Enums\SenderType;
use App\Jobs\ProcessWooCommerceWebhookJob;
use App\Models\Message;
use App\Models\WhatsAppCheckout;
use App\Models\WooCommerceConfig;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\WhatsAppCheckoutFixtures;
use Tests\TestCase;

class WooCommerceWebhookTest extends TestCase
{
    use RefreshDatabase;
    use WhatsAppCheckoutFixtures;

    private const TOKEN = 'woo-webhook-token';

    private const SECRET = 'woo-webhook-secret';

    private WooCommerceConfig $woo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        Queue::fakeExcept([ProcessWooCommerceWebhookJob::class]);

        $this->woo = WooCommerceConfig::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->woo->webhook_token = self::TOKEN;
        $this->woo->tracking_meta_key = '_tracking';
        $this->woo->setEncryptedWebhookSecret(self::SECRET);
        $this->woo->save();
    }

    public function test_an_invalid_signature_is_rejected(): void
    {
        Http::fake();
        $body = json_encode(['id' => 3980]);

        $this->call('POST', '/api/woocommerce-webhook/'.self::TOKEN, [], [], [], [
            'HTTP_X_WC_WEBHOOK_TOPIC' => 'product.updated',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode('nope'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_the_creation_ping_is_accepted_and_unknown_tokens_are_404(): void
    {
        $this->call('POST', '/api/woocommerce-webhook/'.self::TOKEN, [], [], [], [], 'webhook_id=1')->assertOk();
        $this->call('POST', '/api/woocommerce-webhook/nope', [], [], [], [], 'webhook_id=1')->assertNotFound();
    }

    public function test_a_completed_order_notifies_the_shipment_once(): void
    {
        $this->fakeStore();
        $checkout = $this->awaitingPaymentCheckout(['status' => WhatsAppCheckout::PAID, 'paid_at' => now()]);
        $order = $this->order('completed', $checkout, [['key' => '_tracking', 'value' => 'CP123']]);

        $this->deliver('order.updated', $order)->assertStatus(202);
        $this->deliver('order.updated', $order)->assertStatus(202);

        $messages = $this->systemMessages('Tu pedido #1001 ya está en camino%');
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Número de seguimiento: CP123', $messages->first()->content);

        $checkout->refresh();
        $this->assertSame('CP123', $checkout->tracking_number);
        $this->assertNotNull($checkout->shipped_notified_at);
    }

    public function test_a_processing_order_marks_an_awaiting_checkout_paid_without_writing_to_woo(): void
    {
        $this->fakeStore();
        $checkout = $this->awaitingPaymentCheckout();

        $this->deliver('order.updated', $this->order('processing', $checkout) + ['transaction_id' => 'mp-9'])->assertStatus(202);

        $checkout->refresh();
        $this->assertSame(WhatsAppCheckout::PAID, $checkout->status);
        $this->assertSame('mp-9', $checkout->payment_id);
        $this->assertCount(0, $this->sentTo('PUT', '#/wc/v3/orders/#'));
        $this->assertCount(1, $this->systemMessages('¡Listo, recibimos tu pago!%'));
    }

    public function test_a_cancelled_order_closes_the_checkout_and_expires_the_link(): void
    {
        $this->fakeStore();
        $checkout = $this->awaitingPaymentCheckout();

        $this->deliver('order.updated', $this->order('cancelled', $checkout))->assertStatus(202);

        $this->assertSame(WhatsAppCheckout::CANCELLED, $checkout->fresh()->status);
        $this->assertCount(1, $this->sentTo('PUT', '#api.mercadopago.com/checkout/preferences/pref-1$#'));
    }

    public function test_a_variation_update_refreshes_the_local_variant_stock(): void
    {
        $this->fakeStore(variationStock: 7);

        $this->deliver('product.updated', ['id' => 3983, 'parent_id' => 3980, 'type' => 'variation'])->assertStatus(202);

        $variant = $this->variant->fresh();
        $this->assertSame(7, $variant->stock_quantity);
        $this->assertSame('instock', $variant->stock_status);
        $this->assertTrue($this->product->fresh()->is_active);
    }

    public function test_registering_replaces_the_previous_webhooks(): void
    {
        $this->actAsOwner();
        $this->woo->update(['webhook_ids' => [11, 12]]);
        $created = 100;
        Http::fake(function (Request $request) use (&$created) {
            return match ($request->method()) {
                'DELETE' => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/12') ? Http::response([], 404) : Http::response(['id' => 11]),
                'POST' => Http::response(['id' => ++$created], 201),
                default => Http::response([], 500),
            };
        });

        $this->postJson('/api/woocommerce-config/webhooks')
            ->assertOk()
            ->assertJsonPath('data.webhooks_active', true);

        $this->assertCount(2, $this->sentTo('DELETE', '#/wc/v3/webhooks/1[12]\?force=true$#'));
        $posts = $this->sentTo('POST', '#/wc/v3/webhooks$#');
        $this->assertSame(
            ['product.created', 'product.updated', 'product.deleted', 'product.restored', 'order.updated'],
            $posts->map(fn (Request $r) => $r['topic'])->all(),
        );
        $this->assertSame(config('app.url').'/api/woocommerce-webhook/'.self::TOKEN, $posts->first()['delivery_url']);
        $this->assertSame('active', $posts->first()['status']);

        $config = $this->woo->fresh();
        $this->assertSame([101, 102, 103, 104, 105], $config->webhook_ids);
        $this->assertSame($posts->first()['secret'], $config->getDecryptedWebhookSecret());
    }

    public function test_registering_with_a_read_only_key_returns_422(): void
    {
        $this->actAsOwner();
        Http::fake(['*' => Http::response(['code' => 'woocommerce_rest_cannot_create'], 401)]);

        $this->postJson('/api/woocommerce-config/webhooks')
            ->assertStatus(422)
            ->assertJsonPath('message', 'La clave de WooCommerce necesita permisos de Lectura/Escritura para crear los webhooks.');
    }

    private function actAsOwner(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
        $registrar->setPermissionsTeamId($this->tenant->id);
        $registrar->forgetCachedPermissions();
        $this->user->assignRole('Owner');
        Sanctum::actingAs($this->user);
    }

    /** Producto 3980 con su variación 3983, más el resto de los servicios simulados. */
    private function fakeStore(int $variationStock = 3): void
    {
        Http::fake([
            '*/wc/v3/products/3980/variations*' => Http::response([[
                'id' => 3983, 'status' => 'publish', 'sku' => '', 'price' => '24300',
                'manage_stock' => true, 'stock_quantity' => $variationStock, 'stock_status' => 'instock',
            ]], 200, ['X-WP-TotalPages' => '1']),
            '*/wc/v3/products/3980' => Http::response([
                'id' => 3980, 'name' => 'Conjunto bebé futbol boca', 'type' => 'variable', 'status' => 'publish', 'price' => '24300',
            ]),
        ]);
        $this->fakeCommerce();
    }

    private function order(string $status, WhatsAppCheckout $checkout, array $meta = []): array
    {
        return [
            'id' => 501,
            'number' => '1001',
            'status' => $status,
            'line_items' => [['product_id' => 3980, 'variation_id' => 3983, 'quantity' => 1]],
            'meta_data' => [['key' => 'crm_checkout_id', 'value' => (string) $checkout->id], ...$meta],
        ];
    }

    private function deliver(string $topic, array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/woocommerce-webhook/'.self::TOKEN, [], [], [], [
            'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, self::SECRET, true)),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function systemMessages(string $like)
    {
        return Message::where('conversation_id', $this->conversation->id)
            ->where('sender_type', SenderType::SYSTEM)
            ->where('content', 'like', $like)
            ->get();
    }
}
