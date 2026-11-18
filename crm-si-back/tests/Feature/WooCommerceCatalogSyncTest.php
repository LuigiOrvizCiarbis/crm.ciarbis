<?php

namespace Tests\Feature;

use App\Enums\ContactFieldType;
use App\Enums\UserRole;
use App\Jobs\SyncWooCommerceCatalogPageJob;
use App\Models\Product;
use App\Models\ProductField;
use App\Models\ProductImport;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WooCommerceConfig;
use App\Services\ProductImportService;
use App\Services\WooCommerceCatalogSync;
use App\Services\WooCommerceUrlException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class WooCommerceCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    // IP pública literal: PublicUrlGuard no necesita DNS real.
    private const STORE_URL = 'https://93.184.216.34';

    private User $user;

    /** @var array<int, list<array<string, mixed>>> productos por página */
    private array $pages = [];

    /** @var array<int, list<array<string, mixed>>> variaciones por producto */
    private array $variations = [];

    /** @var array<int, array{status: int, body: mixed}> respuestas forzadas por página de productos */
    private array $pageResponses = [];

    /** @var array<int, array{status: int, body: mixed}> respuestas forzadas de variaciones por producto */
    private array $variationResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->createTenantWithRoles();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $this->user->assignRole('Owner');

        $config = new WooCommerceConfig(['tenant_id' => $tenant->id, 'store_url' => self::STORE_URL, 'enabled' => true]);
        $config->setEncryptedConsumerKey('ck_test');
        $config->setEncryptedConsumerSecret('cs_test');
        $config->save();

        Queue::fake();
        Http::fake(fn (Request $request) => $this->storeResponse($request));
        Sanctum::actingAs($this->user);
    }

    public function test_syncs_products_fields_and_variants_across_pages(): void
    {
        $this->pages = [
            1 => [
                $this->product(10, 'Remera &amp; Co', 'simple', ['sku' => 'REM-1', 'manage_stock' => true, 'stock_quantity' => 7, 'categories' => [['name' => 'Remeras']]]),
                $this->product(20, 'Buzo Oversize', 'variable', ['price' => '15000', 'categories' => [['name' => 'Buzos']]]),
            ],
            2 => [
                $this->product(30, 'Gift card', 'simple', ['virtual' => true, 'stock_status' => 'outofstock']),
            ],
        ];
        $this->variations[20] = [
            $this->variation(201, ['Talle' => 'M'], '15000', 3),
            $this->variation(202, ['Talle' => 'L'], '16000', 0, 'outofstock'),
            $this->variation(203, ['Talle' => 'XL'], '16000', 1, 'instock', 'private'),
        ];

        $this->postJson('/api/woocommerce-config/sync')
            ->assertStatus(202)
            ->assertJsonPath('data.sync.status', 'running');
        $this->runQueuedSync();

        $this->getJson('/api/woocommerce-config')
            ->assertOk()
            ->assertJsonPath('data.sync.status', 'completed')
            ->assertJsonPath('data.sync.result.created', 3)
            ->assertJsonPath('data.sync.result.variants', 2)
            ->assertJsonPath('data.sync.result.deactivated', 0);

        $remera = $this->wooProduct(10);
        $this->assertSame('Remera & Co', $remera->name);
        $this->assertSame('REM-1', $remera->custom_data['sku']);
        $this->assertSame(7, $remera->custom_data['stock']);
        $this->assertSame('En stock', $remera->custom_data['stock_status']);
        $this->assertSame(['Remeras'], $remera->custom_data['categories']);
        $this->assertSame('simple', $remera->external_type);
        $this->assertTrue($remera->requires_shipping);

        $giftCard = $this->wooProduct(30);
        $this->assertFalse($giftCard->requires_shipping);
        $this->assertSame('Sin stock', $giftCard->custom_data['stock_status']);

        $variants = $this->wooProduct(20)->variants;
        $this->assertSame(['201', '202'], $variants->pluck('external_id')->all());
        $this->assertSame(['Talle' => 'L'], $variants[1]->attribute_values);
        $this->assertSame('outofstock', $variants[1]->stock_status);

        // Las categorías nuevas se suman a las opciones del campo para que el
        // producto se pueda seguir editando sin errores de validación.
        $categories = ProductField::withoutGlobalScopes()->where('key', 'categories')->first();
        $this->assertSame(ContactFieldType::MultiSelect, $categories->type);
        $this->assertSame(['Remeras', 'Buzos'], $categories->options['choices']);
        $this->assertNotNull(WooCommerceConfig::first()->last_synced_at);
    }

    public function test_resync_deactivates_missing_products_and_removes_missing_variants(): void
    {
        $manual = Product::create(['tenant_id' => $this->user->tenant_id, 'name' => 'Producto manual', 'source' => 'manual']);
        $this->pages = [1 => [
            $this->product(10, 'Remera', 'simple'),
            $this->product(20, 'Buzo', 'variable'),
        ]];
        $this->variations[20] = [
            $this->variation(201, ['Talle' => 'M'], '100', 1),
            $this->variation(202, ['Talle' => 'L'], '100', 1),
        ];
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();

        $this->pages = [1 => [$this->product(20, 'Buzo', 'variable')]];
        $this->variations[20] = [$this->variation(202, ['Talle' => 'L'], '120', 4)];
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();

        $this->assertFalse($this->wooProduct(10)->is_active);
        $this->assertTrue($this->wooProduct(20)->is_active);
        $this->assertTrue($manual->fresh()->is_active);
        $this->assertSame(['202'], ProductVariant::withoutGlobalScopes()->pluck('external_id')->all());
        $this->assertSame(1, WooCommerceConfig::first()->sync_result['deactivated']);
    }

    public function test_failed_page_marks_run_failed_without_deactivating(): void
    {
        $this->pages = [1 => [$this->product(10, 'Remera', 'simple')]];
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();
        $lastSyncedAt = WooCommerceConfig::first()->last_synced_at;

        $this->pages = [1 => [$this->product(20, 'Buzo', 'simple')], 2 => []];
        $this->pageResponses = [2 => ['status' => 500, 'body' => ['message' => 'error']]];
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();

        $config = WooCommerceConfig::first();
        $this->assertSame(WooCommerceConfig::SYNC_FAILED, $config->sync_status);
        $this->assertStringContainsString('(500)', $config->sync_error);
        $this->assertEquals($lastSyncedAt, $config->last_synced_at);
        $this->assertTrue($this->wooProduct(10)->is_active);
    }

    public function test_rejects_a_second_sync_while_one_is_running(): void
    {
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);

        $this->postJson('/api/woocommerce-config/sync')->assertStatus(409);
        Queue::assertPushed(SyncWooCommerceCatalogPageJob::class, 1);
    }

    public function test_a_stalled_run_is_reported_and_can_be_restarted(): void
    {
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);

        // El worker murió: la corrida sigue "running" pero no avanza.
        $this->travel(21)->minutes();
        $this->getJson('/api/woocommerce-config')
            ->assertJsonPath('data.sync.status', 'running')
            ->assertJsonPath('data.sync.stale', true);

        $this->postJson('/api/woocommerce-config/sync')
            ->assertStatus(202)
            ->assertJsonPath('data.sync.stale', false);
        Queue::assertPushed(SyncWooCommerceCatalogPageJob::class, 2);
    }

    public function test_a_non_list_response_fails_the_run_instead_of_deactivating_everything(): void
    {
        $this->pages = [1 => [$this->product(10, 'Remera', 'simple')]];
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();

        // WordPress sin enlaces permanentes devuelve la home en HTML con 200.
        $this->pageResponses = [1 => ['status' => 200, 'body' => '<!doctype html><html><body>Tienda</body></html>']];
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();

        $config = WooCommerceConfig::first();
        $this->assertSame(WooCommerceConfig::SYNC_FAILED, $config->sync_status);
        $this->assertStringContainsString('no devolvió una lista válida', $config->sync_error);
        $this->assertTrue($this->wooProduct(10)->is_active);
    }

    public function test_a_non_list_variations_response_keeps_the_existing_variants(): void
    {
        $this->pages = [1 => [$this->product(20, 'Buzo', 'variable')]];
        $this->variations[20] = [$this->variation(201, ['Talle' => 'M'], '100', 1)];
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();

        $this->variationResponses = [20 => ['status' => 200, 'body' => ['code' => 'maintenance']]];
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();

        $this->assertSame(WooCommerceConfig::SYNC_FAILED, WooCommerceConfig::first()->sync_status);
        $this->assertSame(['201'], $this->wooProduct(20)->variants->pluck('external_id')->all());
    }

    public function test_rejected_credentials_surface_as_the_sync_error(): void
    {
        $this->pageResponses = [1 => ['status' => 401, 'body' => ['code' => 'woocommerce_rest_cannot_view']]];

        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();

        $this->getJson('/api/woocommerce-config')
            ->assertJsonPath('data.sync.status', 'failed')
            ->assertJsonPath('data.sync.error', 'WooCommerce rechazó las credenciales al leer los productos (página 1).');
    }

    public function test_undecryptable_credentials_return_422_without_starting_a_run(): void
    {
        // Credenciales encriptadas con una APP_KEY anterior.
        DB::table('woocommerce_configs')->update(['consumer_key' => 'cifrado-con-otra-key']);

        $this->postJson('/api/woocommerce-config/sync')->assertStatus(422);

        $this->assertNull(WooCommerceConfig::first()->sync_status);
        Queue::assertNothingPushed();
    }

    public function test_a_failed_dispatch_does_not_leave_the_run_running(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('Redis caído'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->postJson('/api/woocommerce-config/sync')->assertStatus(500);

        $config = WooCommerceConfig::first();
        $this->assertSame(WooCommerceConfig::SYNC_FAILED, $config->sync_status);
        $this->assertSame('No se pudo encolar la sincronización. Probá de nuevo.', $config->sync_error);
    }

    public function test_jobs_of_a_superseded_run_change_nothing(): void
    {
        $this->pages = [1 => [$this->product(10, 'Remera', 'simple')]];
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $staleJob = Queue::pushed(SyncWooCommerceCatalogPageJob::class)->first();
        $staleRun = app(WooCommerceCatalogSync::class)->activeRun($this->user->tenant_id, $staleJob->runId);
        $untouched = Product::create([
            'tenant_id' => $this->user->tenant_id, 'name' => 'Ya sincronizado', 'source' => 'woocommerce', 'external_id' => '99',
        ]);

        $this->travel(21)->minutes();
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $currentRunId = WooCommerceConfig::first()->sync_run_id;

        // Un job encolado de la corrida vieja no hace nada…
        app()->call([$staleJob, 'handle']);
        // …tampoco uno que ya estaba en curso al ser reemplazada: no cierra ni desactiva…
        app(WooCommerceCatalogSync::class)->syncPage($staleRun, 1);
        // …y su failed() no pisa la corrida nueva.
        $staleJob->failed(new RuntimeException('timeout'));

        $config = WooCommerceConfig::first();
        $this->assertSame($currentRunId, $config->sync_run_id);
        $this->assertSame(WooCommerceConfig::SYNC_RUNNING, $config->sync_status);
        $this->assertTrue($untouched->fresh()->is_active);
    }

    public function test_deactivation_only_affects_the_syncing_tenant(): void
    {
        $otherTenant = $this->createTenantWithRoles();
        $otherProduct = Product::create([
            'tenant_id' => $otherTenant->id, 'name' => 'De otra tienda', 'source' => 'woocommerce', 'external_id' => '10',
        ]);
        $this->pages = [1 => [$this->product(20, 'Buzo', 'simple')]];

        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();

        $this->assertSame(0, WooCommerceConfig::first()->sync_result['deactivated']);
        $this->assertTrue($otherProduct->fresh()->is_active);
    }

    public function test_a_dns_failure_is_retried_instead_of_failing_the_run(): void
    {
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        WooCommerceConfig::query()->update(['store_url' => 'https://tienda-inexistente.invalid']);
        $job = Queue::pushed(SyncWooCommerceCatalogPageJob::class)->first();

        try {
            app()->call([$job, 'handle']);
            $this->fail('El job debía relanzar el error para que la cola lo reintente.');
        } catch (WooCommerceUrlException $exception) {
            $this->assertTrue($exception->retryable);
        }
        $this->assertSame(WooCommerceConfig::SYNC_RUNNING, WooCommerceConfig::first()->sync_status);
    }

    public function test_import_updates_only_crm_owned_data_of_woo_products(): void
    {
        ProductField::create([
            'tenant_id' => $this->user->tenant_id, 'key' => 'notas', 'label' => 'Notas',
            'type' => ContactFieldType::Text, 'display_order' => 5,
        ]);
        $this->pages = [1 => [$this->product(10, 'Remera', 'simple', ['sku' => 'REM-1'])]];
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();

        Storage::fake('local');
        $path = "product-imports/{$this->user->tenant_id}/import.csv";
        Storage::disk('local')->put($path, "sku,nombre,precio,notas\nREM-1,Remera pisada,1,Llega el lunes\n");
        $import = ProductImport::withoutGlobalScopes()->create([
            'tenant_id' => $this->user->tenant_id, 'requested_by' => $this->user->id,
            'original_filename' => 'import.csv', 'file_path' => $path, 'status' => 'queued',
            'mode' => 'upsert', 'match_field' => 'custom:sku', 'preserve_empty' => true,
            'mapping' => ['has_headers' => true, 'name' => 1, 'price' => 2, 'custom' => ['sku' => 0, 'notas' => 3]],
            'proposed_fields' => [], 'queued_at' => now(),
        ]);

        $result = app(ProductImportService::class)->runQueued($import);

        $product = $this->wooProduct(10);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('Remera', $product->name);
        $this->assertSame('1000.00', $product->price);
        $this->assertSame('REM-1', $product->custom_data['sku']);
        $this->assertSame('Llega el lunes', $product->custom_data['notas']);
    }

    public function test_tenant_field_with_another_type_is_neither_synced_nor_locked(): void
    {
        ProductField::create([
            'tenant_id' => $this->user->tenant_id, 'key' => 'stock', 'label' => 'Stock',
            'type' => ContactFieldType::Text, 'display_order' => 5,
        ]);
        $this->pages = [1 => [$this->product(10, 'Remera', 'simple', ['manage_stock' => true, 'stock_quantity' => 3])]];

        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();

        $product = $this->wooProduct(10);
        $this->assertArrayNotHasKey('stock', $product->custom_data);
        $this->assertSame(['Stock'], WooCommerceConfig::first()->sync_result['skipped_fields']);

        // El sync no lo escribe, así que el campo propio sigue editable.
        $managedFields = $this->putJson("/api/products/{$product->id}", ['custom_data' => ['stock' => 'Pedir al depósito']])
            ->assertOk()
            ->assertJsonPath('data.custom_data.stock', 'Pedir al depósito')
            ->json('data.managed_fields');
        $this->assertNotContains('stock', $managedFields);
        $this->assertContains('sku', $managedFields);
    }

    public function test_woo_managed_data_cannot_be_edited_in_the_crm(): void
    {
        ProductField::create([
            'tenant_id' => $this->user->tenant_id, 'key' => 'notas', 'label' => 'Notas',
            'type' => ContactFieldType::Text, 'display_order' => 5,
        ]);
        $this->pages = [1 => [$this->product(10, 'Remera', 'simple', ['sku' => 'REM-1'])]];
        $this->postJson('/api/woocommerce-config/sync')->assertStatus(202);
        $this->runQueuedSync();
        $product = $this->wooProduct(10);

        $this->putJson("/api/products/{$product->id}", ['name' => 'Otro nombre'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
        $this->putJson("/api/products/{$product->id}", ['custom_data' => ['sku' => 'OTRO']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('custom_data.sku');

        // Reenviar los datos sin cambios y editar un campo propio sí se puede.
        $this->putJson("/api/products/{$product->id}", [
            'name' => 'Remera',
            'price' => 1000,
            'is_active' => true,
            'custom_data' => ['sku' => 'REM-1', 'notas' => 'Llega el lunes'],
        ])
            ->assertOk()
            ->assertJsonPath('data.custom_data.notas', 'Llega el lunes')
            ->assertJsonPath('data.managed_fields.0', 'name');
    }

    /**
     * Ejecuta los jobs encolados en orden, incluidos los que encola cada página.
     * Un job que tira excepción se trata como agotado (failed()).
     */
    private function runQueuedSync(): void
    {
        $handled = Queue::pushed(SyncWooCommerceCatalogPageJob::class)->count() - 1;

        while (($job = Queue::pushed(SyncWooCommerceCatalogPageJob::class)->values()->get($handled)) !== null) {
            $handled++;
            try {
                app()->call([$job, 'handle']);
            } catch (Throwable $exception) {
                $job->failed($exception);
            }
        }
    }

    private function storeResponse(Request $request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);

        if (preg_match('#/wp-json/wc/v3/products/(\d+)/variations$#', $path, $match)) {
            $forced = $this->variationResponses[(int) $match[1]] ?? null;

            return $forced
                ? Http::response($forced['body'], $forced['status'])
                : Http::response($this->variations[(int) $match[1]] ?? [], 200, ['X-WP-TotalPages' => '1']);
        }

        if (isset($this->pageResponses[$page])) {
            return Http::response($this->pageResponses[$page]['body'], $this->pageResponses[$page]['status']);
        }

        return Http::response($this->pages[$page] ?? [], 200, ['X-WP-TotalPages' => (string) count($this->pages)]);
    }

    private function wooProduct(int $externalId): Product
    {
        return Product::withoutGlobalScopes()->where('source', 'woocommerce')->where('external_id', (string) $externalId)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function product(int $id, string $name, string $type, array $overrides = []): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'status' => 'publish',
            'price' => '1000',
            'short_description' => '<p>Descripción</p>',
            'description' => '',
            'sku' => '',
            'manage_stock' => false,
            'stock_quantity' => null,
            'stock_status' => 'instock',
            'virtual' => false,
            'categories' => [],
            'images' => [['src' => "https://cdn.example.com/{$id}.jpg"]],
            'permalink' => "https://tienda.example.com/p/{$id}",
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     */
    private function variation(int $id, array $attributes, string $price, int $stock, string $stockStatus = 'instock', string $status = 'publish'): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'sku' => "VAR-{$id}",
            'price' => $price,
            'manage_stock' => true,
            'stock_quantity' => $stock,
            'stock_status' => $stockStatus,
            'image' => null,
            'attributes' => array_map(
                fn (string $name, string $option): array => ['id' => 1, 'name' => $name, 'option' => $option],
                array_keys($attributes),
                array_values($attributes),
            ),
        ];
    }
}
