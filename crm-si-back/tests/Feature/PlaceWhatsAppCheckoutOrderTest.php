<?php

namespace Tests\Feature;

use App\Enums\SenderType;
use App\Jobs\PlaceWhatsAppCheckoutOrderJob;
use App\Jobs\SendWhatsAppCheckoutPaymentLinkJob;
use App\Models\Message;
use App\Models\WhatsAppCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\WhatsAppCheckoutFixtures;
use Tests\TestCase;

class PlaceWhatsAppCheckoutOrderTest extends TestCase
{
    use RefreshDatabase;
    use WhatsAppCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->setUpCheckoutWorld();
        Queue::fake([SendWhatsAppCheckoutPaymentLinkJob::class]);
        $this->fakeCommerce();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_creates_the_order_on_hold_and_the_preference(): void
    {
        $checkout = $this->placing();

        $this->runJob($checkout);

        $order = $this->sentTo('POST', '#/wc/v3/orders$#')->sole();
        $this->assertSame('on-hold', $order['status']);
        $this->assertSame(3983, $order['line_items'][0]['variation_id']);
        $this->assertSame(3980, $order['line_items'][0]['product_id']);
        $this->assertSame([['key' => 'tipo', 'value' => 'clasico']], $order['shipping_lines'][0]['meta_data']);
        $this->assertContains(['key' => 'crm_checkout_id', 'value' => (string) $checkout->id], $order['meta_data']);

        $preference = $this->sentTo('POST', '#api\.mercadopago\.com/checkout/preferences$#')->sole();
        $this->assertSame("CRM-{$checkout->id}", $preference['external_reference']);
        $this->assertStringEndsWith('/api/mercadopago-webhook/'.self::MP_WEBHOOK_TOKEN, $preference['notification_url']);
        $this->assertSame([['id' => 'ticket'], ['id' => 'atm']], $preference['payment_methods']['excluded_payment_types']);
        $this->assertTrue(Carbon::parse($preference['expiration_date_to'])->equalTo(now()->addHours(2)));
        $this->assertStringContainsString('pedido=1001', $preference['back_urls']['success']);
        $this->assertStringContainsString('wa=5491100000000', $preference['back_urls']['success']);

        $checkout->refresh();
        $this->assertSame(WhatsAppCheckout::AWAITING_PAYMENT, $checkout->status);
        $this->assertSame(501, $checkout->external_order_id);
        $this->assertSame('1001', $checkout->external_order_number);
        $this->assertSame('pref-1', $checkout->payment_preference_id);
        Queue::assertPushed(SendWhatsAppCheckoutPaymentLinkJob::class, fn ($job) => $job->checkoutId === $checkout->id);
    }

    public function test_a_different_total_cancels_the_order_and_sends_a_new_form(): void
    {
        $this->wooOrderTotal = '40000.00';
        $checkout = $this->placing();
        $previousSession = $checkout->whatsapp_flow_session_id;

        $this->runJob($checkout);

        $this->assertSame('cancelled', $this->sentTo('PUT', '#/wc/v3/orders/501$#')->sole()['status']);
        $this->assertBackToCart($checkout, $previousSession, 'No pudimos confirmar tu pedido: El total cambió. Volvé a revisar el envío.');
        $this->assertCount(0, $this->sentTo('POST', '#api\.mercadopago\.com#'));
    }

    public function test_a_mercadopago_failure_cancels_the_created_order(): void
    {
        $this->mpPreferenceStatus = 500;
        $checkout = $this->placing();
        $previousSession = $checkout->whatsapp_flow_session_id;

        $this->runJob($checkout);

        $this->assertSame('cancelled', $this->sentTo('PUT', '#/wc/v3/orders/501$#')->sole()['status']);
        $this->assertBackToCart($checkout, $previousSession, 'No pudimos confirmar tu pedido: No pudimos generar el link de pago. Probá de nuevo.');
        $this->assertNull($checkout->fresh()->external_order_id);
    }

    public function test_does_nothing_when_the_checkout_is_no_longer_placing(): void
    {
        $checkout = $this->makeCheckout(['status' => WhatsAppCheckout::CART] + $this->quotedAttributes());

        $this->runJob($checkout);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function placing(): WhatsAppCheckout
    {
        $this->waConfig->update(['display_phone_number' => '+54 9 11 0000-0000']);

        return $this->makeCheckout(['status' => WhatsAppCheckout::PLACING] + $this->quotedAttributes());
    }

    private function runJob(WhatsAppCheckout $checkout): void
    {
        app()->call([new PlaceWhatsAppCheckoutOrderJob($checkout->id), 'handle']);
    }

    private function assertBackToCart(WhatsAppCheckout $checkout, int $previousSession, string $text): void
    {
        $checkout->refresh();
        $this->assertSame(WhatsAppCheckout::CART, $checkout->status);
        $this->assertNull($checkout->payment_expires_at);
        $this->assertNotSame($previousSession, $checkout->whatsapp_flow_session_id);
        $this->assertNotNull($checkout->whatsapp_flow_session_id);
        $this->assertTrue(Message::where('conversation_id', $this->conversation->id)
            ->where('sender_type', SenderType::SYSTEM)
            ->where('content', $text)
            ->exists());
        Queue::assertNotPushed(SendWhatsAppCheckoutPaymentLinkJob::class);
    }
}
