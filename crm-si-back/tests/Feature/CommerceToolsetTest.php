<?php

namespace Tests\Feature;

use App\Models\MercadoPagoConfig;
use App\Models\ProductVariant;
use App\Models\WhatsAppCheckout;
use App\Services\Commerce\CommerceToolset;
use App\Services\Commerce\CommerceToolsetFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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

    public function test_show_catalog_sends_the_whatsapp_catalog(): void
    {
        $toolset = $this->toolset();

        $this->assertSame('Catálogo enviado.', $toolset->call(CommerceToolset::SHOW_CATALOG, []));

        $sent = $this->graphMessages();
        $this->assertCount(1, $sent);
        $this->assertSame('catalog_message', $sent[0]['interactive']['type']);
        $this->assertSame('Te mandé el catálogo.', $toolset->fallbackMessage());
    }

    public function test_a_product_not_yet_enabled_in_whatsapp_is_left_out_without_blocking_the_catalog(): void
    {
        ProductVariant::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'external_id' => '3984', 'attribute_values' => ['Talle' => '4'], 'stock_status' => 'instock']);
        $this->missingRetailerIds = ['3983'];

        $result = $this->toolset()->call(CommerceToolset::SHOW_PRODUCTS, ['product_ids' => [$this->product->id]]);

        $this->assertSame('Productos enviados.', $result);
        $sent = $this->graphMessages();
        $this->assertCount(2, $sent);
        $this->assertSame('product', $sent[1]['interactive']['type']);
        $this->assertSame('3984', $sent[1]['interactive']['action']['product_retailer_id']);

        // El rechazado no se vuelve a pedir y el catálogo sigue ofreciendo fichas.
        $this->assertSame('Productos enviados.', $this->toolset()->call(CommerceToolset::SHOW_PRODUCTS, ['product_ids' => [$this->product->id]]));
        $this->assertCount(3, $this->graphMessages());
        $this->assertSame('3984', $this->graphMessages()[2]['interactive']['action']['product_retailer_id']);
        $this->assertContains(CommerceToolset::SHOW_PRODUCTS, array_map(fn ($tool) => $tool->name, $this->toolset()->definitions()));
    }

    public function test_when_none_of_the_products_is_enabled_the_model_is_told_it_is_not_about_stock(): void
    {
        $this->missingRetailerIds = ['3983'];

        $result = $this->toolset()->call(CommerceToolset::SHOW_PRODUCTS, ['product_ids' => [$this->product->id]]);

        $this->assertStringContainsString('no tiene que ver con el stock', $result);
        $this->assertContains(CommerceToolset::SHOW_PRODUCTS, array_map(fn ($tool) => $tool->name, $this->toolset()->definitions()));
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
        $checkout = $this->awaitingPaymentCheckout();
        $toolset = $this->toolset();

        $this->assertSame('Link reenviado.', $toolset->call(CommerceToolset::RESEND_PAYMENT_LINK, []));
        $sent = $this->graphMessages();
        $this->assertCount(1, $sent);
        $this->assertSame('cta_url', $sent[0]['interactive']['type']);
        // Límite de WhatsApp para el texto del botón: con más, Meta rechaza el mensaje entero.
        $this->assertLessThanOrEqual(20, mb_strlen($sent[0]['interactive']['action']['parameters']['display_text']));
        $this->assertSame($checkout->payment_url, $sent[0]['interactive']['action']['parameters']['url']);
        // El tenant de la prueba tiene el timezone por defecto ("UTC"): el vencimiento se muestra en hora local.
        $local = $checkout->payment_expires_at->copy()->setTimezone('America/Argentina/Buenos_Aires')->format('H:i');
        $this->assertStringContainsString("El link vence a las {$local}.", $sent[0]['interactive']['body']['text']);
        $this->assertSame('Te reenvié el link de pago.', $toolset->fallbackMessage());
    }

    public function test_resend_payment_link_without_pending_order(): void
    {
        $toolset = $this->toolset();

        $this->assertSame('No hay un pedido esperando pago.', $toolset->call(CommerceToolset::RESEND_PAYMENT_LINK, []));
        $this->assertSame([], $this->graphMessages());
        $this->assertNull($toolset->fallbackMessage());
    }

    public function test_unavailable_catalog_is_not_reported_as_out_of_stock_and_cards_stay_offered(): void
    {
        $this->catalogProductsMissing = true;

        $result = $this->toolset()->call(CommerceToolset::SHOW_PRODUCTS, ['product_ids' => [$this->product->id]]);

        $this->assertStringContainsString('no tiene que ver con el stock', $result);
        $this->assertStringContainsString('enviar_formulario_compra', $result);
        $this->assertStringNotContainsString('131009', $result);

        // Un rechazo no bloquea las fichas: el próximo mensaje vuelve a intentarlo.
        $this->catalogProductsMissing = false;
        $this->toolset()->call(CommerceToolset::SHOW_PRODUCTS, ['product_ids' => [$this->product->id]]);
        $this->assertCount(2, $this->graphMessages());
    }
}
