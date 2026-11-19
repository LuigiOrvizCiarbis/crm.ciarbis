<?php

namespace Tests\Feature;

use App\Jobs\PlaceWhatsAppCheckoutOrderJob;
use App\Models\WhatsAppCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Tests\Feature\Concerns\WhatsAppCheckoutFixtures;
use Tests\TestCase;

class WhatsAppCheckoutFlowEndpointTest extends TestCase
{
    use RefreshDatabase;
    use WhatsAppCheckoutFixtures;

    private const SECRET = 'test-secret';

    /** Respuestas forzadas del batch de la Store API, en orden: [status, body]. */
    private array $batchOverrides = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.facebook.app_secret' => self::SECRET]);
        $this->setUpCheckoutWorld(withFlowKeys: true);
        Queue::fake();
        Http::fake(fn (Request $request) => $this->store($request));
    }

    public function test_init_shows_cart_and_states(): void
    {
        $checkout = $this->makeCheckout();

        $data = $this->exchange($checkout, ['action' => 'INIT']);

        $this->assertSame('DATOS', $data['screen']);
        $this->assertSame('1 × Conjunto bebé futbol boca (2) $24.300', $data['data']['cart_text']);
        $this->assertSame([['id' => 'C', 'title' => 'Ciudad Autónoma de Buenos Aires'], ['id' => 'B', 'title' => 'Buenos Aires']], $data['data']['states']);
        $this->assertTrue($data['data']['has_states']);
        $this->assertSame('Jane', $data['data']['first_name']);
        $this->assertSame('Doe', $data['data']['last_name']);
    }

    public function test_shipping_quote_requires_street(): void
    {
        $checkout = $this->makeCheckout();

        $data = $this->dataExchange($checkout, 'DATOS', ['crm_action' => 'shipping_quote', 'street' => ''] + $this->address());

        $this->assertSame('DATOS', $data['screen']);
        $this->assertTrue($data['data']['has_error']);
        $this->assertSame('Revisá: Calle', $data['data']['error_message']);
    }

    public function test_shipping_quote_shows_rates_from_the_store(): void
    {
        $checkout = $this->makeCheckout();

        $data = $this->dataExchange($checkout, 'DATOS', ['crm_action' => 'shipping_quote'] + $this->address());

        $this->assertSame('ENVIO', $data['screen']);
        $this->assertSame([
            ['id' => 'correoargentino_shipping_method:5', 'title' => 'Correo Argentino clásico a domicilio · $10.396', 'description' => '3 a 6 días hábiles'],
            ['id' => 'correoargentino_shipping_method:6', 'title' => 'Correo Argentino expreso a domicilio · $15.000', 'description' => '1 a 3 días hábiles'],
        ], $data['data']['shipping_options']);

        $checkout->refresh();
        $this->assertSame('1043', $checkout->shipping_address['postal_code']);
        $this->assertSame('cart-token-1', $checkout->cart_token);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/wc/store/v1/batch')
            && $r->header('Cart-Token')[0] === 'cart-token-1'
            && $r['requests'][0]['body'] === ['id' => 3983, 'quantity' => 1]);
    }

    public function test_shipping_quote_shows_the_stock_error(): void
    {
        $checkout = $this->makeCheckout();
        $this->batchOverrides[] = [['responses' => [['status' => 400, 'body' => ['message' => '<strong>No hay suficiente stock</strong> de &quot;Conjunto&quot;']]]], 207];

        $data = $this->dataExchange($checkout, 'DATOS', ['crm_action' => 'shipping_quote'] + $this->address());

        $this->assertSame('DATOS', $data['screen']);
        $this->assertSame('No hay suficiente stock de "Conjunto"', $data['data']['error_message']);
    }

    public function test_review_with_rejected_coupon_shows_error(): void
    {
        $checkout = $this->makeCheckout(['shipping_rate' => null, 'total' => null] + $this->quotedAttributes());

        $data = $this->dataExchange($checkout, 'ENVIO', ['crm_action' => 'review', 'shipping_rate_id' => 'correoargentino_shipping_method:5', 'coupon_code' => 'NADA']);

        $this->assertSame('ENVIO', $data['screen']);
        $this->assertTrue($data['data']['has_error']);
        $this->assertSame('El cupón "nada" no existe.', $data['data']['error_message']);
        $this->assertNotEmpty($data['data']['shipping_options']);
    }

    public function test_review_without_coupon_change_makes_no_calls(): void
    {
        $checkout = $this->makeCheckout(['shipping_rate' => null, 'total' => null] + $this->quotedAttributes());

        $data = $this->dataExchange($checkout, 'ENVIO', ['crm_action' => 'review', 'shipping_rate_id' => 'correoargentino_shipping_method:5', 'coupon_code' => '']);

        $this->assertSame('RESUMEN', $data['screen']);
        $this->assertStringContainsString('Total: $34.696', $data['data']['summary_text']);
        $this->assertSame('34696.00', $checkout->fresh()->total);
        Http::assertNothingSent();
    }

    public function test_review_with_new_coupon_uses_the_saved_cart_token(): void
    {
        $checkout = $this->makeCheckout(['shipping_rate' => null, 'total' => null] + $this->quotedAttributes());

        $data = $this->dataExchange($checkout, 'ENVIO', ['crm_action' => 'review', 'shipping_rate_id' => 'correoargentino_shipping_method:5', 'coupon_code' => ' PROMO ']);

        $this->assertSame('RESUMEN', $data['screen']);
        $this->assertStringContainsString('Descuento (promo): -$2.430', $data['data']['summary_text']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/batch') && $r->header('Cart-Token')[0] === 'cart-token-1');
        $this->assertSame('promo', $checkout->fresh()->coupon_code);
    }

    public function test_review_falls_back_to_a_full_quote_when_the_cart_token_expired(): void
    {
        $checkout = $this->makeCheckout(['shipping_rate' => null, 'total' => null] + $this->quotedAttributes());
        $this->batchOverrides[] = [['code' => 'woocommerce_rest_cart_token_invalid', 'message' => 'Forbidden'], 403];

        $data = $this->dataExchange($checkout, 'ENVIO', ['crm_action' => 'review', 'shipping_rate_id' => 'correoargentino_shipping_method:5', 'coupon_code' => 'promo']);

        $this->assertSame('RESUMEN', $data['screen']);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/wc/store/v1/cart'));
    }

    public function test_place_order_queues_the_job_without_calling_the_store(): void
    {
        $checkout = $this->makeCheckout($this->quotedAttributes());

        $data = $this->dataExchange($checkout, 'RESUMEN', ['crm_action' => 'place_order']);

        $this->assertSame('SUCCESS', $data['screen']);
        $this->assertSame($checkout->id, $data['data']['extension_message_response']['params']['checkout_id']);
        $this->assertSame(WhatsAppCheckout::PLACING, $checkout->fresh()->status);
        Queue::assertPushed(PlaceWhatsAppCheckoutOrderJob::class, 1);
        Http::assertNothingSent();

        $again = $this->dataExchange($checkout, 'RESUMEN', ['crm_action' => 'place_order']);

        $this->assertSame('SUCCESS', $again['screen']);
        Queue::assertPushed(PlaceWhatsAppCheckoutOrderJob::class, 1);
    }

    private function address(): array
    {
        return [
            'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '1122334455', 'email' => '',
            'street' => 'Corrientes', 'street_number' => '1234', 'apartment' => '', 'city' => 'CABA',
            'postal_code' => 'c 1043 abc', 'state' => 'C',
        ];
    }

    private function store(Request $request)
    {
        $url = $request->url();

        return match (true) {
            str_contains($url, 'graph.facebook.com') => Http::response(['messages' => [['id' => 'wamid.x']]]),
            str_contains($url, '/wc/v3/settings/general') => Http::response([['id' => 'woocommerce_default_country', 'value' => 'AR:C']]),
            str_contains($url, '/wc/v3/data/countries/AR') => Http::response(['code' => 'AR', 'states' => [
                ['code' => 'C', 'name' => 'Ciudad Autónoma de Buenos Aires'],
                ['code' => 'B', 'name' => 'Buenos Aires'],
            ]]),
            str_ends_with($url, '/wc/store/v1/cart') => Http::response($this->storeCart(), 200, ['Cart-Token' => 'cart-token-1']),
            str_ends_with($url, '/wc/store/v1/batch') => Http::response(...($this->batchOverrides !== [] ? array_shift($this->batchOverrides) : $this->batch($request))),
            default => Http::response(['message' => 'unexpected '.$url], 500),
        };
    }

    /** Respuesta del batch según sus requests: el cupón "nada" no existe; "promo" descuenta 10 %. */
    private function batch(Request $request): array
    {
        $coupons = [];
        $discount = 0;
        $responses = [];

        foreach ($request['requests'] as $item) {
            $code = $item['body']['code'] ?? null;
            if (str_ends_with($item['path'], '/apply-coupon') && $code === 'nada') {
                $responses[] = ['status' => 400, 'body' => ['message' => 'El cupón &quot;nada&quot; no existe.']];

                continue;
            }
            if (str_ends_with($item['path'], '/apply-coupon')) {
                $coupons = [['code' => $code, 'totals' => ['total_discount' => '243000', 'total_discount_tax' => '0']]];
                $discount = 243000;
            }
            if (str_ends_with($item['path'], '/remove-coupon')) {
                [$coupons, $discount] = [[], 0];
            }
            $responses[] = ['status' => 200, 'body' => $this->storeCart($coupons, $discount)];
        }

        return [['responses' => $responses], 207];
    }

    private function dataExchange(WhatsAppCheckout $checkout, string $screen, array $data): array
    {
        return $this->exchange($checkout, ['action' => 'data_exchange', 'screen' => $screen, 'data' => $data]);
    }

    private function exchange(WhatsAppCheckout $checkout, array $payload): array
    {
        $token = $checkout->flowSession()->withoutGlobalScopes()->value('flow_token');
        [$response, $aesKey, $iv] = $this->send(['version' => '3.0', 'flow_token' => $token] + $payload);
        $response->assertOk();

        return $this->decrypt($response->getContent(), $aesKey, $iv);
    }

    /** @return array{0: TestResponse, 1: string, 2: string} */
    private function send(array $payload): array
    {
        $aesKey = random_bytes(16);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt(json_encode($payload), 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
        /** @var RSA\PublicKey $rsa */
        $rsa = PublicKeyLoader::loadPublicKey($this->flowsPublicPem);
        $encryptedKey = $rsa->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256')->encrypt($aesKey);

        $raw = json_encode([
            'encrypted_flow_data' => base64_encode($cipher.$tag),
            'encrypted_aes_key' => base64_encode($encryptedKey),
            'initial_vector' => base64_encode($iv),
        ]);

        $response = $this->call('POST', '/api/whatsapp-flow-endpoint/endpoint-token', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, self::SECRET),
        ], $raw);

        return [$response, $aesKey, $iv];
    }

    private function decrypt(string $body, string $aesKey, string $iv): array
    {
        $decoded = base64_decode($body);
        $plain = openssl_decrypt(substr($decoded, 0, -16), 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, ~$iv, substr($decoded, -16));
        $this->assertNotFalse($plain, 'La respuesta no se pudo descifrar con el IV invertido.');

        return json_decode($plain, true);
    }
}
