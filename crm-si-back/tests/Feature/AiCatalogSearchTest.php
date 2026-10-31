<?php

namespace Tests\Feature;

use App\Enums\ContactFieldType;
use App\Models\AiConfig;
use App\Models\Product;
use App\Models\ProductField;
use App\Models\Tenant;
use App\Services\Ai\CatalogProductSearch;
use App\Services\AiReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AiCatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    private function messages(string $latest, ?string $previous = null): array
    {
        $messages = $previous === null ? [] : [['role' => 'user', 'content' => $previous]];
        $messages[] = ['role' => 'user', 'content' => $latest];

        return $messages;
    }

    private function prompt(int $tenantId, array $messages): string
    {
        $method = new ReflectionMethod(AiReplyService::class, 'systemPrompt');

        return $method->invoke(app(AiReplyService::class), new AiConfig(['system_prompt' => 'Asistente de prueba']), $tenantId, $messages);
    }

    public function test_finds_a_product_beyond_the_old_alphabetical_limit(): void
    {
        $tenant = Tenant::create(['name' => 'Petplus']);
        for ($i = 0; $i < 105; $i++) {
            Product::create(['tenant_id' => $tenant->id, 'name' => sprintf('A Producto %03d', $i), 'price' => 100]);
        }
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Z Fawna Cachorro', 'price' => 250]);

        $prompt = $this->prompt($tenant->id, $this->messages('¿Tienen Fawna Cachorro?'));

        $this->assertStringContainsString('Z Fawna Cachorro', $prompt);
        $this->assertStringNotContainsString('A Producto 000', $prompt);
    }

    public function test_uses_recent_customer_context_for_short_follow_up(): void
    {
        $tenant = Tenant::create(['name' => 'Petplus']);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Fawna Cachorro 3 kg', 'price' => 320]);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Otra Marca 3 kg', 'price' => 300]);

        $result = app(CatalogProductSearch::class)->search($tenant->id, $this->messages('¿Y el de 3 kg?', '¿Tienen Fawna Cachorro?'), 1);

        $this->assertSame('Fawna Cachorro 3 kg', $result['products'][0]->name);
        $this->assertSame(2, $result['matches']);
    }

    public function test_new_product_question_does_not_inherit_previous_product(): void
    {
        $tenant = Tenant::create(['name' => 'Petplus']);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Fawna Cachorro']);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Acana Gato']);

        $result = app(CatalogProductSearch::class)->search($tenant->id, $this->messages('Acana', 'Fawna Cachorro'), 12);

        $this->assertSame(1, $result['matches']);
        $this->assertSame('Acana Gato', $result['products'][0]->name);
    }

    public function test_searches_and_displays_configured_fields_and_excludes_inactive_or_other_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Petplus']);
        $other = Tenant::create(['name' => 'Otra tienda']);
        ProductField::create(['tenant_id' => $tenant->id, 'key' => 'categoria', 'label' => 'Categoría', 'type' => ContactFieldType::Text]);
        ProductField::create(['tenant_id' => $tenant->id, 'key' => 'codigo', 'label' => 'Código', 'type' => ContactFieldType::Text]);
        $deletedField = ProductField::create(['tenant_id' => $tenant->id, 'key' => 'interno', 'label' => 'Interno', 'type' => ContactFieldType::Text]);
        $deletedField->delete();
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Bolsa Arándano', 'price' => 120, 'custom_data' => ['categoria' => 'Alimento gatos', 'codigo' => 'PET-42', 'interno' => 'Secreto']]);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Bolsa inactiva', 'is_active' => false, 'custom_data' => ['codigo' => 'PET-42']]);
        Product::create(['tenant_id' => $other->id, 'name' => 'Bolsa de otra tienda', 'custom_data' => ['codigo' => 'PET-42']]);

        $prompt = $this->prompt($tenant->id, $this->messages('Precio PET-42'));
        $category = app(CatalogProductSearch::class)->search($tenant->id, $this->messages('alimentos para gatos'), 12);

        $this->assertStringContainsString('Bolsa Arándano', $prompt);
        $this->assertStringContainsString('Categoría: Alimento gatos', $prompt);
        $this->assertStringContainsString('Código: PET-42', $prompt);
        $this->assertStringNotContainsString('Secreto', $prompt);
        $this->assertStringNotContainsString('Bolsa inactiva', $prompt);
        $this->assertStringNotContainsString('Bolsa de otra tienda', $prompt);
        $this->assertSame(1, $category['matches']);
    }

    public function test_exact_code_ranks_before_partial_matches(): void
    {
        $tenant = Tenant::create(['name' => 'Petplus']);
        ProductField::create(['tenant_id' => $tenant->id, 'key' => 'codigo', 'label' => 'Código', 'type' => ContactFieldType::Text]);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'PET 42 Juguete', 'custom_data' => ['codigo' => 'OTRO-1']]);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Juguete correcto', 'custom_data' => ['codigo' => 'PET-42']]);

        $result = app(CatalogProductSearch::class)->search($tenant->id, $this->messages('¿Tienen PET-42?'), 1);

        $this->assertSame('Juguete correcto', $result['products'][0]->name);
    }

    public function test_broad_and_missing_queries_do_not_claim_to_show_the_whole_catalog(): void
    {
        $tenant = Tenant::create(['name' => 'Petplus']);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Alimento Gato A', 'price' => 100]);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Alimento Gato B', 'price' => 200]);
        config(['services.ai.catalog_max_products' => 1]);

        $broad = $this->prompt($tenant->id, $this->messages('¿Qué alimentos para gatos tienen?'));
        $generic = $this->prompt($tenant->id, $this->messages('¿Qué productos tienen?'));
        $missing = $this->prompt($tenant->id, $this->messages('¿Tienen marca Inexistente?'));
        $unrelated = $this->prompt($tenant->id, $this->messages('¿Cuál es el horario?'));

        $this->assertStringContainsString('Hay más productos coincidentes', $broad);
        $this->assertStringContainsString('Hay 2 productos activos', $generic);
        $this->assertStringNotContainsString('no se encontró una coincidencia', $generic);
        $this->assertStringContainsString('no se encontró una coincidencia', $missing);
        $this->assertStringNotContainsString('Catálogo de productos', $unrelated);
    }

    public function test_catalog_section_obeys_character_budget(): void
    {
        $tenant = Tenant::create(['name' => 'Petplus']);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Fawna Cachorro', 'price' => 100, 'description' => str_repeat('dato ', 100)]);
        config(['services.ai.catalog_max_chars' => 230]);

        $prompt = $this->prompt($tenant->id, $this->messages('Fawna Cachorro'));
        $section = explode("\n\n", $prompt)[1];

        $this->assertLessThanOrEqual(230, mb_strlen($section));
    }

    public function test_image_without_caption_does_not_reuse_an_old_product_query(): void
    {
        $tenant = Tenant::create(['name' => 'Petplus']);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Fawna Cachorro', 'price' => 100]);

        $result = app(CatalogProductSearch::class)->search($tenant->id, $this->messages('[El cliente envió una imagen]', 'Fawna Cachorro'), 12);

        $this->assertSame(0, $result['matches']);
    }
}
