<?php

namespace Tests\Feature;

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\Product;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\WhatsAppCheckoutFixtures;
use Tests\TestCase;

/** Plantillas con botón "Ver catálogo": se envían con una portada que Meta acepte. */
class WhatsAppCatalogTemplateTest extends TestCase
{
    use RefreshDatabase;
    use WhatsAppCheckoutFixtures;

    private WhatsAppTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutWorld();
        $this->fakeCommerce();
        $this->template = WhatsAppTemplate::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_config_id' => $this->waConfig->id,
            'external_id' => 'tpl-catalogo',
            'name' => 'novedades_catalogo',
            'language' => 'es_AR',
            'category' => TemplateCategory::Marketing,
            'status' => TemplateStatus::Approved,
            'components' => [
                ['type' => 'BODY', 'text' => 'Llegaron camisetas nuevas. Mirá el catálogo y armá tu pedido.'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'CATALOG', 'text' => 'Ver catálogo']]],
            ],
        ]);
    }

    public function test_catalog_template_uses_an_enabled_product_as_thumbnail_and_remembers_it(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Body River', 'price' => 23140, 'is_active' => true, 'source' => 'woocommerce', 'external_id' => '3798']);
        Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Llavero', 'price' => 1815, 'is_active' => true, 'source' => 'woocommerce', 'external_id' => '2816']);
        $this->missingRetailerIds = ['2816'];

        app(WhatsAppTemplateService::class)->sendSystemTemplateMessage($this->conversation, $this->template, []);

        // El más nuevo (2816) no está habilitado: se prueba con el siguiente.
        $this->assertSame(['2816', '3798'], $this->sentThumbnails());
        $this->assertSame('0', $this->sentTemplates()->last()['template']['components'][0]['index']);

        app(WhatsAppTemplateService::class)->sendSystemTemplateMessage($this->conversation, $this->template, []);

        $this->assertSame(['2816', '3798', '3798'], $this->sentThumbnails());
    }

    public function test_template_without_catalog_button_is_sent_untouched(): void
    {
        $this->template->update(['components' => [['type' => 'BODY', 'text' => 'Hola']]]);
        Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Body River', 'price' => 23140, 'is_active' => true, 'source' => 'woocommerce', 'external_id' => '3798']);

        app(WhatsAppTemplateService::class)->sendSystemTemplateMessage($this->conversation, $this->template, []);

        $this->assertSame([], $this->sentTemplates()->sole()['template']['components']);
    }

    /** @return Collection<int, Request> */
    private function sentTemplates(): Collection
    {
        return Http::recorded(fn (Request $request) => ($request['type'] ?? null) === 'template')
            ->map(fn (array $pair) => $pair[0])
            ->values();
    }

    /** @return list<?string> */
    private function sentThumbnails(): array
    {
        return $this->sentTemplates()
            ->map(fn (Request $request) => collect($request['template']['components'])->firstWhere('sub_type', 'CATALOG')['parameters'][0]['action']['thumbnail_product_retailer_id'] ?? null)
            ->all();
    }
}
