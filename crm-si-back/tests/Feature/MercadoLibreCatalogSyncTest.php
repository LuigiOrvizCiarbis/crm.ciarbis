<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MercadoLibreConnection;
use App\Models\MercadoLibreListing;
use App\Models\MercadoLibreProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\MercadoLibre\MercadoLibreCatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MercadoLibreCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.mercadolibre.com';

    private User $user;

    /** @var list<string> atributos que la categoría exige */
    private array $requiredAttributes = ['BRAND'];

    /** @var ?array{status: int, body: array<string, mixed>} respuesta forzada de POST /items */
    private ?array $createResponse = null;

    /** @var ?array{status: int, body: array<string, mixed>} respuesta forzada de POST /oauth/token */
    private ?array $tokenResponse = null;

    private int $createdItems = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->createTenantWithRoles();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $this->user->assignRole('Owner');
        Sanctum::actingAs($this->user);

        config([
            'services.mercadolibre.client_id' => 'app-123',
            'services.mercadolibre.client_secret' => 'secret',
            'services.mercadolibre.redirect_uri' => 'https://crm.test/api/mercadolibre/callback',
            'app.frontend_url' => 'https://app.test',
        ]);

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->mercadoLibre($request));
    }

    public function test_active_product_is_published_with_predicted_category_and_kept_in_sync(): void
    {
        $this->connect();

        $product = Product::create([
            'tenant_id' => $this->user->tenant_id,
            'name' => 'Remera Acme',
            'price' => 1500,
            'description' => '<p>Algodón peinado</p>',
            'custom_data' => ['image_url' => 'https://cdn.test/remera.jpg', 'sku' => 'R-1', 'stock' => 5],
        ]);

        $create = $this->sent('POST', '/items');
        $this->assertCount(1, $create);
        $payload = $create[0]->data();
        $this->assertSame('Remera Acme', $payload['family_name']);
        $this->assertArrayNotHasKey('title', $payload);
        $this->assertSame('MLA1234', $payload['category_id']);
        $this->assertEquals(1500, $payload['price']);
        $this->assertSame('ARS', $payload['currency_id']);
        $this->assertSame(5, $payload['available_quantity']);
        $this->assertSame('gold_special', $payload['listing_type_id']);
        $this->assertSame([['source' => 'https://cdn.test/remera.jpg']], $payload['pictures']);
        $this->assertSame('Algodón peinado', $payload['description']['plain_text']);
        $this->assertContains(['id' => 'BRAND', 'value_id' => '9', 'value_name' => 'Acme'], $payload['attributes']);
        $this->assertContains(['id' => 'SELLER_SKU', 'value_name' => 'R-1'], $payload['attributes']);

        $listing = MercadoLibreListing::withoutGlobalScopes()->where('product_id', $product->id)->sole();
        $this->assertSame(MercadoLibreListing::STATUS_SYNCED, $listing->status);
        $this->assertSame('MLA1', $listing->ml_item_id);
        $this->assertTrue(MercadoLibreProduct::withoutGlobalScopes()->where('product_id', $product->id)->sole()->category_predicted);

        $product->update(['price' => 1800]);

        $update = $this->sent('PUT', '/items/MLA1');
        $this->assertCount(1, $update);
        $this->assertEquals(1800, $update[0]->data()['price']);
        $this->assertSame(5, $update[0]->data()['available_quantity']);
        $this->assertArrayNotHasKey('family_name', $update[0]->data());

        // Sin cambios no se vuelve a llamar a ML.
        app(MercadoLibreCatalogSync::class)->syncProduct($product->tenant_id, $product->id);
        $this->assertCount(1, $this->sent('PUT', '/items/MLA1'));
    }

    public function test_product_missing_required_attributes_waits_until_they_are_completed(): void
    {
        $this->requiredAttributes = ['BRAND', 'MODEL'];
        $this->connect();

        $product = Product::create([
            'tenant_id' => $this->user->tenant_id,
            'name' => 'Remera Acme',
            'price' => 1500,
            'custom_data' => ['image_url' => 'https://cdn.test/remera.jpg'],
        ]);

        $this->assertCount(0, $this->sent('POST', '/items'));
        $listing = MercadoLibreListing::withoutGlobalScopes()->where('product_id', $product->id)->sole();
        $this->assertSame(MercadoLibreListing::STATUS_NEEDS_ATTRIBUTES, $listing->status);
        $this->assertSame([['id' => 'MODEL', 'name' => 'Modelo']], $listing->missing);

        $this->getJson("/api/products/{$product->id}/mercadolibre")
            ->assertOk()
            ->assertJsonPath('data.category_id', 'MLA1234')
            ->assertJsonPath('data.category_predicted', true)
            ->assertJsonPath('data.listings.0.status', 'needs_attributes');

        $this->putJson("/api/products/{$product->id}/mercadolibre", [
            'enabled' => true,
            'category_id' => 'MLA1234',
            'attributes' => [
                ['id' => 'BRAND', 'value_id' => '9', 'value_name' => 'Acme'],
                ['id' => 'MODEL', 'value_id' => null, 'value_name' => 'Clásica'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.category_predicted', false)
            ->assertJsonPath('data.listings.0.status', 'synced');

        $this->assertCount(1, $this->sent('POST', '/items'));
        $this->assertContains(['id' => 'MODEL', 'value_name' => 'Clásica'], $this->sent('POST', '/items')[0]->data()['attributes']);
    }

    public function test_each_variant_is_its_own_item_and_out_of_stock_variants_wait(): void
    {
        $product = Product::create(['tenant_id' => $this->user->tenant_id, 'name' => 'Buzo Acme', 'price' => 3000]);
        $red = ProductVariant::create([
            'tenant_id' => $product->tenant_id, 'product_id' => $product->id, 'external_id' => '1',
            'attribute_values' => ['Color' => 'Rojo', 'Estampa' => 'Lisa'], 'stock_quantity' => 3, 'image_url' => 'https://cdn.test/rojo.jpg',
        ]);
        ProductVariant::create([
            'tenant_id' => $product->tenant_id, 'product_id' => $product->id, 'external_id' => '2',
            'attribute_values' => ['Color' => 'Azul'], 'stock_quantity' => 0, 'image_url' => 'https://cdn.test/azul.jpg',
        ]);
        $this->connect();

        app(MercadoLibreCatalogSync::class)->syncProduct($product->tenant_id, $product->id);

        $create = $this->sent('POST', '/items');
        $this->assertCount(1, $create);
        $attributes = $create[0]->data()['attributes'];
        $this->assertContains(['id' => 'COLOR', 'value_id' => '51993', 'value_name' => 'Rojo'], $attributes);
        $this->assertContains(['name' => 'Estampa', 'value_name' => 'Lisa'], $attributes);
        $this->assertSame([['source' => 'https://cdn.test/rojo.jpg']], $create[0]->data()['pictures']);

        $statuses = MercadoLibreListing::withoutGlobalScopes()->where('product_id', $product->id)->pluck('status', 'product_variant_id');
        $this->assertSame(MercadoLibreListing::STATUS_SYNCED, $statuses[$red->id]);
        $this->assertCount(2, $statuses);
        $this->assertContains(MercadoLibreListing::STATUS_PAUSED, $statuses->all());
    }

    public function test_inactive_product_is_paused_and_deleted_product_is_closed(): void
    {
        $this->connect();
        $product = $this->publishableProduct();
        $this->assertCount(1, $this->sent('POST', '/items'));

        $product->update(['is_active' => false]);

        $this->assertSame(['status' => 'paused'], $this->sent('PUT', '/items/MLA1')[0]->data());
        $this->assertSame(MercadoLibreListing::STATUS_PAUSED, MercadoLibreListing::withoutGlobalScopes()->sole()->status);

        $product->delete();

        $this->assertSame(['status' => 'closed'], $this->sent('PUT', '/items/MLA1')[1]->data());
        $this->assertSame(0, MercadoLibreListing::withoutGlobalScopes()->count());
    }

    public function test_rejected_item_keeps_mercado_libre_error_on_the_listing(): void
    {
        $this->createResponse = ['status' => 400, 'body' => [
            'message' => 'Validation error',
            'cause' => [
                ['type' => 'warning', 'message' => 'Agregá más fotos.'],
                ['type' => 'error', 'message' => 'The attribute GTIN is required.'],
            ],
        ]];
        $this->connect();

        $product = $this->publishableProduct();

        $listing = MercadoLibreListing::withoutGlobalScopes()->where('product_id', $product->id)->sole();
        $this->assertSame(MercadoLibreListing::STATUS_ERROR, $listing->status);
        $this->assertSame('The attribute GTIN is required.', $listing->error);

        $this->getJson('/api/mercadolibre/connection')
            ->assertOk()
            ->assertJsonPath('data.summary.needs_attention', 1);
        $this->getJson('/api/mercadolibre/listings?status=needs_attention')
            ->assertOk()
            ->assertJsonPath('data.0.product_name', 'Remera Acme')
            ->assertJsonPath('data.0.error', 'The attribute GTIN is required.');
    }

    public function test_expired_access_token_is_refreshed_once_and_replaced(): void
    {
        $connection = $this->connect(expiresAt: now()->subMinute());

        $this->publishableProduct();

        $tokenRequests = $this->sent('POST', '/oauth/token');
        $this->assertCount(1, $tokenRequests);
        $this->assertSame('refresh_token', $tokenRequests[0]->data()['grant_type']);
        $this->assertSame('TG-old', $tokenRequests[0]->data()['refresh_token']);

        $connection->refresh();
        $this->assertSame('APP_USR-new', $connection->getDecryptedAccessToken());
        $this->assertSame('TG-new', $connection->getDecryptedRefreshToken());
        $this->assertTrue($this->sent('POST', '/items')[0]->hasHeader('Authorization', 'Bearer APP_USR-new'));
    }

    public function test_revoked_authorization_marks_connection_for_reconnection(): void
    {
        $this->tokenResponse = ['status' => 400, 'body' => ['error' => 'invalid_grant']];
        $connection = $this->connect(expiresAt: now()->subMinute());

        $this->publishableProduct();

        $this->assertSame(MercadoLibreConnection::STATUS_NEEDS_REAUTH, $connection->fresh()->status);
        $this->assertCount(0, $this->sent('POST', '/items'));
    }

    public function test_oauth_callback_connects_the_seller_account(): void
    {
        $url = $this->postJson('/api/mercadolibre/authorization-url')->assertOk()->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://auth.mercadolibre.com.ar/authorization?', $url);
        $this->assertSame('S256', $query['code_challenge_method']);

        $this->get('/api/mercadolibre/callback?code=TG-code&state='.$query['state'])
            ->assertRedirect('https://app.test/configuracion/integraciones?mercadolibre=connected#mercadolibre');

        $connection = MercadoLibreConnection::forTenant($this->user->tenant_id);
        $this->assertSame(MercadoLibreConnection::STATUS_CONNECTED, $connection->status);
        $this->assertSame(777, $connection->ml_user_id);
        $this->assertSame('MLA', $connection->site_id);
        $this->assertSame('ARS', $connection->currency_id);
        $this->assertTrue($connection->user_products);
        $this->assertSame('APP_USR-new', $connection->getDecryptedAccessToken());
        $this->assertNotSame('APP_USR-new', $connection->getRawOriginal('access_token'));
        $exchange = $this->sent('POST', '/oauth/token')[0]->data();
        $this->assertSame('authorization_code', $exchange['grant_type']);
        $this->assertNotEmpty($exchange['code_verifier']);

        $response = $this->getJson('/api/mercadolibre/connection')
            ->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.nickname', 'TIENDA_ACME');
        $this->assertStringNotContainsString('APP_USR-new', $response->getContent());

        // El state es de un solo uso.
        $this->get('/api/mercadolibre/callback?code=TG-code&state='.$query['state'])
            ->assertRedirect('https://app.test/configuracion/integraciones?mercadolibre=expired#mercadolibre');
    }

    public function test_daily_command_refreshes_idle_accounts_without_publishing(): void
    {
        $connection = $this->connect(syncEnabled: false);

        $this->artisan('mercadolibre:sync')->assertSuccessful();

        $tokenRequests = $this->sent('POST', '/oauth/token');
        $this->assertCount(1, $tokenRequests);
        $this->assertSame('TG-old', $tokenRequests[0]->data()['refresh_token']);
        $this->assertSame('TG-new', $connection->fresh()->getDecryptedRefreshToken());
        $this->assertCount(0, $this->sent('POST', '/items'));
    }

    public function test_daily_command_flags_accounts_that_lost_authorization(): void
    {
        $this->tokenResponse = ['status' => 400, 'body' => ['error' => 'invalid_grant']];
        $connection = $this->connect();
        $this->publishableProductWithoutSync($connection);

        $this->artisan('mercadolibre:sync')->assertSuccessful();

        $this->assertSame(MercadoLibreConnection::STATUS_NEEDS_REAUTH, $connection->fresh()->status);
        $this->assertCount(0, $this->sent('POST', '/items'));
    }

    /** Producto creado sin que el observer lo publique. */
    private function publishableProductWithoutSync(MercadoLibreConnection $connection): Product
    {
        $connection->forceFill(['sync_enabled' => false])->save();
        $product = $this->publishableProduct();
        $connection->forceFill(['sync_enabled' => true])->save();

        return $product;
    }

    private function publishableProduct(): Product
    {
        return Product::create([
            'tenant_id' => $this->user->tenant_id,
            'name' => 'Remera Acme',
            'price' => 1500,
            'custom_data' => ['image_url' => 'https://cdn.test/remera.jpg'],
        ]);
    }

    private function connect(?\DateTimeInterface $expiresAt = null, bool $syncEnabled = true): MercadoLibreConnection
    {
        $connection = new MercadoLibreConnection([
            'tenant_id' => $this->user->tenant_id,
            'status' => MercadoLibreConnection::STATUS_CONNECTED,
            'ml_user_id' => 777,
            'nickname' => 'TIENDA_ACME',
            'site_id' => 'MLA',
            'currency_id' => 'ARS',
            'user_products' => true,
            'sync_enabled' => $syncEnabled,
        ]);
        $connection->setTokens('APP_USR-old', 'TG-old', 3600);
        $connection->token_expires_at = $expiresAt ?? now()->addHour();
        $connection->save();

        return $connection;
    }

    /**
     * @return list<Request>
     */
    private function sent(string $method, string $path): array
    {
        return Http::recorded(fn (Request $request) => $request->method() === $method && parse_url($request->url(), PHP_URL_PATH) === $path)
            ->map(fn (array $pair) => $pair[0])
            ->values()
            ->all();
    }

    private function mercadoLibre(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $route = $request->method().' '.$path;

        return match (true) {
            $route === 'POST /oauth/token' => $this->tokenResponse !== null
                ? Http::response($this->tokenResponse['body'], $this->tokenResponse['status'])
                : Http::response(['access_token' => 'APP_USR-new', 'refresh_token' => 'TG-new', 'expires_in' => 21600, 'user_id' => 777]),
            $route === 'GET /users/me' => Http::response(['id' => 777, 'nickname' => 'TIENDA_ACME', 'site_id' => 'MLA', 'tags' => ['normal', 'user_product_seller']]),
            $route === 'GET /sites/MLA' => Http::response(['id' => 'MLA', 'default_currency_id' => 'ARS']),
            $route === 'GET /sites/MLA/domain_discovery/search' => Http::response([[
                'domain_id' => 'MLA-T_SHIRTS', 'domain_name' => 'Remeras', 'category_id' => 'MLA1234', 'category_name' => 'Remeras',
                'attributes' => [['id' => 'BRAND', 'name' => 'Marca', 'value_id' => '9', 'value_name' => 'Acme']],
            ]]),
            $route === 'GET /categories/MLA1234/attributes' => Http::response($this->categoryAttributes()),
            $route === 'POST /items' => $this->createResponse !== null
                ? Http::response($this->createResponse['body'], $this->createResponse['status'])
                : Http::response(['id' => 'MLA'.(++$this->createdItems), 'status' => 'active', 'permalink' => 'https://articulo.test/MLA'.$this->createdItems]),
            $request->method() === 'PUT' && str_starts_with($path, '/items/') => Http::response(['status' => $request->data()['status'] ?? 'active']),
            default => Http::response(['message' => 'not found'], 404),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categoryAttributes(): array
    {
        $attribute = fn (string $id, string $name, array $extra = []) => [
            'id' => $id,
            'name' => $name,
            'value_type' => 'string',
            'tags' => in_array($id, $this->requiredAttributes, true) ? ['required' => true] : [],
            ...$extra,
        ];

        return [
            $attribute('BRAND', 'Marca'),
            $attribute('MODEL', 'Modelo'),
            $attribute('COLOR', 'Color', ['value_type' => 'list', 'values' => [['id' => '51993', 'name' => 'Rojo'], ['id' => '52028', 'name' => 'Azul']]]),
            $attribute('SELLER_SKU', 'SKU'),
            ['id' => 'ITEM_ID', 'name' => 'Ítem', 'tags' => ['read_only' => true]],
        ];
    }
}
