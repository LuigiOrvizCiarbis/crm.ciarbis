<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MercadoPagoConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MercadoPagoConfigTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->createTenantWithRoles();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $this->user->assignRole('Owner');
        Sanctum::actingAs($this->user);
    }

    public function test_invalid_token_is_rejected(): void
    {
        Http::fake(['https://api.mercadopago.com/users/me' => Http::response(['message' => 'invalid'], 401)]);

        $this->putJson('/api/mercadopago-config', ['access_token' => 'APP_USR-bad'])
            ->assertStatus(422)
            ->assertJsonPath('errors.access_token.0', 'La credencial de Mercado Pago no es válida.');

        $this->assertDatabaseCount('mercadopago_configs', 0);
    }

    public function test_valid_token_is_stored_encrypted_with_webhook_token(): void
    {
        Http::fake(['https://api.mercadopago.com/users/me' => Http::response(['id' => 1, 'nickname' => 'TIENDA'])]);

        $this->putJson('/api/mercadopago-config', ['access_token' => 'APP_USR-good', 'statement_descriptor' => 'TIENDA'])
            ->assertOk()
            ->assertJsonPath('data.has_credentials', true)
            ->assertJsonPath('data.statement_descriptor', 'TIENDA');

        $config = MercadoPagoConfig::withoutGlobalScopes()->where('tenant_id', $this->user->tenant_id)->firstOrFail();
        $this->assertNotSame('APP_USR-good', $config->getRawOriginal('access_token'));
        $this->assertSame('APP_USR-good', $config->getDecryptedAccessToken());
        $this->assertSame(48, strlen($config->webhook_token));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer APP_USR-good'));
    }

    public function test_show_hides_token(): void
    {
        $config = new MercadoPagoConfig(['tenant_id' => $this->user->tenant_id, 'webhook_token' => str_repeat('a', 48)]);
        $config->setEncryptedAccessToken('APP_USR-secret');
        $config->save();

        $response = $this->getJson('/api/mercadopago-config')
            ->assertOk()
            ->assertJsonPath('data.has_credentials', true);

        $this->assertStringNotContainsString('APP_USR-secret', $response->getContent());
        $this->assertArrayNotHasKey('access_token', $response->json('data'));
        $this->assertArrayNotHasKey('webhook_token', $response->json('data'));
    }
}
