<?php

namespace Tests\Feature;

use App\Enums\SenderType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Jobs\ProcessMercadoPagoPaymentJob;
use App\Models\HumanHandoff;
use App\Models\Message;
use App\Models\Opportunity;
use App\Models\WhatsAppCheckout;
use App\Models\WhatsAppTemplate;
use App\Services\Commerce\WhatsAppCheckoutTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\WhatsAppCheckoutFixtures;
use Tests\TestCase;

class MercadoPagoWebhookTest extends TestCase
{
    use RefreshDatabase;
    use WhatsAppCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        // El job del webhook corre sync; los demás (avisos del handoff) quedan encolados.
        Queue::fakeExcept([ProcessMercadoPagoPaymentJob::class]);
        $this->fakeCommerce();
    }

    public function test_an_approved_payment_marks_the_order_paid(): void
    {
        $checkout = $this->awaitingPaymentCheckout();
        $this->mpPayment = ['id' => 777, 'status' => 'approved', 'external_reference' => "CRM-{$checkout->id}"];

        $this->notify(self::MP_WEBHOOK_TOKEN)->assertOk();

        $put = $this->sentTo('PUT', '#/wc/v3/orders/501$#')->sole();
        $this->assertSame('processing', $put['status']);
        $this->assertSame('777', $put['transaction_id']);

        $checkout->refresh();
        $this->assertSame(WhatsAppCheckout::PAID, $checkout->status);
        $this->assertSame('777', $checkout->payment_id);
        $this->assertNotNull($checkout->paid_at);

        $opportunity = Opportunity::withoutGlobalScopes()->findOrFail($checkout->opportunity_id);
        $this->assertSame('won', $opportunity->status);
        $this->assertEquals(34696, (float) $opportunity->value);
        $this->assertSame('Pedido #1001', $opportunity->title);
        $this->assertSame(1, $this->paidMessages());

        // Mercado Pago repite la notificación: no hay un segundo PUT ni otro aviso.
        $this->notify(self::MP_WEBHOOK_TOKEN)->assertOk();

        $this->assertCount(1, $this->sentTo('PUT', '#/wc/v3/orders/501$#'));
        $this->assertSame(1, $this->paidMessages());
    }

    public function test_with_the_window_closed_the_installed_paid_template_is_used_without_choosing_it(): void
    {
        $checkout = $this->awaitingPaymentCheckout();
        Message::withTrashed()->forceDelete();
        WhatsAppTemplate::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_config_id' => $this->waConfig->id,
            'external_id' => 'tpl-pagado',
            'name' => WhatsAppCheckoutTemplates::PAID,
            'language' => WhatsAppCheckoutTemplates::LANGUAGE,
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'parameter_format' => 'NAMED',
            'components' => [['type' => 'BODY', 'text' => '¡Recibimos tu pago! Tu pedido #{{pedido}} por {{total}} está confirmado.']],
        ]);
        $this->mpPayment = ['id' => 777, 'status' => 'approved', 'external_reference' => "CRM-{$checkout->id}"];

        $this->notify(self::MP_WEBHOOK_TOKEN)->assertOk();

        $sent = $this->sentTo('POST', '#graph\.facebook\.com/.+/messages$#')->filter(fn ($request) => ($request['type'] ?? null) === 'template')->values();
        $this->assertCount(1, $sent);
        $this->assertSame(WhatsAppCheckoutTemplates::PAID, $sent[0]['template']['name']);
        $this->assertSame(
            ['1001', '$34.696'],
            array_column($sent[0]['template']['components'][0]['parameters'], 'text'),
        );
    }

    public function test_payments_from_other_sales_are_ignored(): void
    {
        $checkout = $this->awaitingPaymentCheckout();
        $this->mpPayment = ['id' => 778, 'status' => 'approved', 'external_reference' => 'WOO-5'];

        $this->notify(self::MP_WEBHOOK_TOKEN)->assertOk();

        $this->assertCount(0, $this->sentTo('PUT', '#/wc/v3/orders/#'));
        $this->assertSame(WhatsAppCheckout::AWAITING_PAYMENT, $checkout->fresh()->status);
    }

    public function test_unknown_token_returns_404(): void
    {
        $this->notify('nope')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_a_late_payment_is_recorded_and_handed_to_a_person(): void
    {
        $checkout = $this->awaitingPaymentCheckout(['status' => WhatsAppCheckout::EXPIRED, 'closed_at' => now()]);
        $this->mpPayment = ['id' => 779, 'status' => 'approved', 'external_reference' => "CRM-{$checkout->id}"];

        $this->notify(self::MP_WEBHOOK_TOKEN)->assertOk();

        $this->assertSame('processing', $this->sentTo('PUT', '#/wc/v3/orders/501$#')->sole()['status']);
        $this->assertSame(WhatsAppCheckout::PAID, $checkout->fresh()->status);
        $this->assertTrue(HumanHandoff::withoutGlobalScopes()
            ->where('conversation_id', $this->conversation->id)
            ->where('reason', 'whatsapp_checkout_late_payment')
            ->exists());
    }

    private function notify(string $token)
    {
        return $this->postJson("/api/mercadopago-webhook/{$token}", ['type' => 'payment', 'data' => ['id' => '777']]);
    }

    private function paidMessages(): int
    {
        return Message::where('conversation_id', $this->conversation->id)
            ->where('sender_type', SenderType::SYSTEM)
            ->where('content', 'like', '¡Listo, recibimos tu pago!%')
            ->count();
    }
}
