<?php

namespace Tests\Feature;

use App\Enums\ContactFieldType;
use App\Models\Product;
use App\Models\ProductField;
use App\Models\Tenant;
use App\Services\Ai\CatalogProductSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('requires-postgres')]
class AiCatalogPostgresSearchTest extends TestCase
{
    use RefreshDatabase;

    private function search(int $tenantId, string $question): array
    {
        return app(CatalogProductSearch::class)->search($tenantId, [['role' => 'user', 'content' => $question]], 12);
    }

    public function test_spanish_search_uses_configured_fields_and_isolates_tenants(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda uno']);
        $other = Tenant::create(['name' => 'Tienda dos']);
        ProductField::create(['tenant_id' => $tenant->id, 'key' => 'categoria', 'label' => 'Categoría', 'type' => ContactFieldType::Text]);
        $removed = ProductField::create(['tenant_id' => $tenant->id, 'key' => 'interno', 'label' => 'Interno', 'type' => ContactFieldType::Text]);
        $removed->delete();
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Bolsa Nutritiva', 'custom_data' => ['categoria' => 'Alimentos gatos']]);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Bolsa neutra', 'custom_data' => ['interno' => 'Alimentos gatos']]);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Bolsa inactiva', 'is_active' => false, 'custom_data' => ['categoria' => 'Alimentos gatos']]);
        Product::create(['tenant_id' => $other->id, 'name' => 'Bolsa ajena', 'custom_data' => ['categoria' => 'Alimentos gatos']]);

        $result = $this->search($tenant->id, '¿Qué alimento para gato tienen?');

        $this->assertSame(1, $result['matches']);
        $this->assertSame('Bolsa Nutritiva', $result['products'][0]->name);
        $this->assertSame(2, $result['active']);
    }

    public function test_exact_code_and_misspelled_name_are_found(): void
    {
        $tenant = Tenant::create(['name' => 'Tienda']);
        ProductField::create(['tenant_id' => $tenant->id, 'key' => 'codigo', 'label' => 'Código', 'type' => ContactFieldType::Text]);
        Product::create(['tenant_id' => $tenant->id, 'name' => 'Fawna Cachorro', 'custom_data' => ['codigo' => 'PET-42']]);

        $code = $this->search($tenant->id, '¿Tienen PET-42?');
        $typo = $this->search($tenant->id, '¿Tienen Fawnaa?');

        $this->assertSame('Fawna Cachorro', $code['products'][0]->name);
        $this->assertSame('Fawna Cachorro', $typo['products'][0]->name);
    }

    public function test_search_indexes_exist(): void
    {
        $names = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'products'"))->pluck('indexname');

        $this->assertContains('products_search_spanish_idx', $names);
        $this->assertContains('products_name_trgm_idx', $names);
    }
}
