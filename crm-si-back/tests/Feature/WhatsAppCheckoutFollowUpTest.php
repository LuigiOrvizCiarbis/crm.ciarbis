<?php

namespace Tests\Feature;

use App\Enums\SenderType;
use App\Models\Message;
use App\Models\WhatsAppCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\WhatsAppCheckoutFixtures;
use Tests\TestCase;

class WhatsAppCheckoutFollowUpTest extends TestCase
{
    use RefreshDatabase;
    use WhatsAppCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->setUpCheckoutWorld();
        Queue::fake();
        $this->fakeCommerce();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reminds_an_unfinished_cart_once_with_a_new_form(): void
    {
        $checkout = $this->createdAgo(now()->subMinutes(65));
        $previousSession = $checkout->whatsapp_flow_session_id;

        $this->artisan('whatsapp-checkouts:follow-up')->assertSuccessful();

        $checkout->refresh();
        $this->assertNotNull($checkout->reminded_at);
        $this->assertNotSame($previousSession, $checkout->whatsapp_flow_session_id);
        $this->assertCount(1, $this->flowSends());

        $this->artisan('whatsapp-checkouts:follow-up')->assertSuccessful();

        $this->assertCount(1, $this->flowSends());
    }

    public function test_discards_a_cart_after_a_day_without_messages(): void
    {
        $checkout = $this->createdAgo(now()->subDay());

        $this->artisan('whatsapp-checkouts:follow-up')->assertSuccessful();

        $checkout->refresh();
        $this->assertSame(WhatsAppCheckout::EXPIRED, $checkout->status);
        $this->assertNotNull($checkout->closed_at);
        Http::assertNothingSent();
    }

    public function test_reminds_the_payment_an_hour_before_it_expires(): void
    {
        $checkout = $this->awaitingPaymentCheckout(['payment_expires_at' => now()->addMinutes(55)]);

        $this->artisan('whatsapp-checkouts:follow-up')->assertSuccessful();
        $this->artisan('whatsapp-checkouts:follow-up')->assertSuccessful();

        $sends = Http::recorded(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com') && ($r['interactive']['type'] ?? null) === 'cta_url');
        $this->assertCount(1, $sends);
        $this->assertSame(1, Message::where('conversation_id', $this->conversation->id)
            ->where('interactive->kind', 'whatsapp_checkout_reminder')
            ->where('interactive->checkout_id', $checkout->id)
            ->count());
        $this->assertNotNull($checkout->fresh()->reminded_at);
    }

    public function test_cancels_an_expired_unpaid_order_and_releases_the_stock(): void
    {
        $checkout = $this->awaitingPaymentCheckout(['payment_expires_at' => now()->subMinute(), 'reminded_at' => now()->subHour()]);

        $this->artisan('whatsapp-checkouts:follow-up')->assertSuccessful();

        $this->assertSame('cancelled', $this->sentTo('PUT', '#/wc/v3/orders/501$#')->sole()['status']);
        $preference = $this->sentTo('PUT', '#api\.mercadopago\.com/checkout/preferences/pref-1$#')->sole();
        $this->assertTrue($preference['expires']);
        $this->assertNotEmpty($preference['expiration_date_to']);

        $checkout->refresh();
        $this->assertSame(WhatsAppCheckout::EXPIRED, $checkout->status);
        $this->assertNotNull($checkout->closed_at);
        $this->assertTrue(Message::where('conversation_id', $this->conversation->id)
            ->where('sender_type', SenderType::SYSTEM)
            ->where('content', 'like', 'Cancelamos el pedido #1001%')
            ->exists());
    }

    public function test_an_expired_order_with_an_approved_payment_is_marked_paid(): void
    {
        $checkout = $this->awaitingPaymentCheckout(['payment_expires_at' => now()->subMinute(), 'reminded_at' => now()->subHour()]);
        $this->mpSearch = [['id' => 900, 'status' => 'approved', 'external_reference' => "CRM-{$checkout->id}"]];

        $this->artisan('whatsapp-checkouts:follow-up')->assertSuccessful();

        $this->assertSame(WhatsAppCheckout::PAID, $checkout->fresh()->status);
        $this->assertSame(['processing'], $this->sentTo('PUT', '#/wc/v3/orders/501$#')->map(fn ($r) => $r['status'])->all());
        $this->assertCount(0, $this->sentTo('PUT', '#api\.mercadopago\.com#'));
    }

    private function createdAgo(Carbon $at): WhatsAppCheckout
    {
        $checkout = $this->makeCheckout();
        $checkout->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

        return $checkout;
    }

    private function flowSends()
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com') && ($r['interactive']['type'] ?? null) === 'flow');
    }
}
