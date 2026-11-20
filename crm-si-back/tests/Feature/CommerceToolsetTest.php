<?php

namespace Tests\Feature;

use App\Models\MercadoPagoConfig;
use App\Models\ProductVariant;
use App\Models\WhatsAppCheckout;
use App\Services\Commerce\CommerceToolset;
use App\Services\Commerce\CommerceToolsetFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\WhatsAppCheckoutFixtures;
use Tests\TestCase;

class CommerceToolsetTest extends TestCase
{
    use RefreshDatabase;
    use WhatsAppCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCheckoutWorld();
        $this->fakeCommerce();
    }

    private function toolset(): CommerceToolset
    {
        return app(CommerceToolsetFactory::class)->forConversation($this->conversation->fresh());
    }

    /** @return list<Request> */
    private function graphMessages(): array
    {
        return $this->sentTo('POST', '#graph\.facebook\.com/.+/messages$#')->all();
    }

    public function test_show_products_sends_in_stock_variants_as_product_list(): void
    {
        ProductVariant::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'external_id' => '3984', 'attribute_values' => ['Talle' => '4'], 'stock_status' => 'onbackorder']);
        ProductVariant::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'external_id' => '3985', 'attribute_values' => ['Talle' => '6'], 'stock_status' => 'outofstock']);
        $toolset = $this->toolset();

        $result = $toolset->call(CommerceToolset::SHOW_PRODUCTS, ['product_ids' => [$this->product->id]]);

        $this->assertSame('Productos enviados.', $result);
        $sent = $this->graphMessages();
        $this->assertCount(1, $sent);
        $this->assertSame('product_list', $sent[0]['interactive']['type']);
        $this->assertSame('catalog-1', $sent[0]['interactive']['action']['catalog_id']);
        $this->assertSame(
            [['product_retailer_id' => '3983'], ['product_retailer_id' => '3984']],
            $sent[0]['interactive']['action']['sections'][0]['product_items'],
        );
        $this->assertSame('Te mandé las opciones.', $toolset->fallbackMessage());
    }

    public function test_send_checkout_opens_a_cart_and_sends_the_flow(): void
    {
        $toolset = $this->toolset();

        $result = $toolset->call(CommerceToolset::SEND_CHECKOUT, ['items' => [
            ['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 2],
        ]]);

        $this->assertSame('Formulario de compra enviado.', $result);
        $checkout = WhatsAppCheckout::withoutGlobalScopes()->sole();
        $this->assertSame(WhatsAppCheckout::CART, $checkout->status);
        $this->assertSame(2, $checkout->items[0]['quantity']);
        $this->assertSame('flow', $this->graphMessages()[0]['interactive']['type']);
        $this->assertSame('Te mandé el formulario para completar la compra.', $toolset->fallbackMessage());
    }

    public function test_factory_returns_null_without_mercado_pago(): void
    {
        MercadoPagoConfig::withoutGlobalScopes()->delete();

        $this->assertNull(app(CommerceToolsetFactory::class)->forConversation($this->conversation->fresh()));
    }

    public function test_prompt_section_describes_the_order_awaiting_payment(): void
    {
        $checkout = $this->awaitingPaymentCheckout();

        $prompt = $this->toolset()->promptSection();

        $this->assertStringContainsString('## Ventas por WhatsApp', $prompt);
        $this->assertStringContainsString('#1001', $prompt);
        $this->assertStringContainsString('esperando pago', $prompt);
        $this->assertStringContainsString($checkout->payment_url, $prompt);
    }

    public function test_resend_payment_link_sends_the_cta_url(): void
    {
        $this->awaitingPaymentCheckout();
        $toolset = $this->toolset();

        $this->assertSame('Link reenviado.', $toolset->call(CommerceToolset::RESEND_PAYMENT_LINK, []));
        $sent = $this->graphMessages();
        $this->assertCount(1, $sent);
        $this->assertSame('cta_url', $sent[0]['interactive']['type']);
        $this->assertSame('Te reenvié el link de pago.', $toolset->fallbackMessage());
    }

    public function test_resend_payment_link_without_pending_order(): void
    {
        $toolset = $this->toolset();

        $this->assertSame('No hay un pedido esperando pago.', $toolset->call(CommerceToolset::RESEND_PAYMENT_LINK, []));
        $this->assertSame([], $this->graphMessages());
        $this->assertNull($toolset->fallbackMessage());
    }
}
