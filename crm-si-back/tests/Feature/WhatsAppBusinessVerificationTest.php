<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WhatsAppBusinessVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.facebook.app_id', 'test-app-id');
        config()->set('services.facebook.app_secret', 'test-app-secret');
        config()->set('services.facebook.graph_version', 'v21.0');
    }

    public function test_returns_verified_status_when_business_id_is_present(): void
    {
        [, $user, $channel] = $this->makeChannel(businessId: 'BIZ_123');
        Sanctum::actingAs($user);

        Http::fake([
            'graph.facebook.com/*/BIZ_123*' => Http::response([
                'id' => 'BIZ_123',
                'name' => 'Acme SA',
                'verification_status' => 'verified',
            ]),
        ]);

        $response = $this->getJson($this->endpoint($channel));

        $response->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.business_id', 'BIZ_123')
            ->assertJsonPath('data.business_name', 'Acme SA')
            ->assertJsonPath('data.verify_url', null); // ya verificado: sin link
    }

    public function test_normalizes_pending_submission_to_pending(): void
    {
        [, $user, $channel] = $this->makeChannel(businessId: 'BIZ_9');
        Sanctum::actingAs($user);

        Http::fake([
            'graph.facebook.com/*/BIZ_9*' => Http::response([
                'id' => 'BIZ_9',
                'name' => 'Tu cumple vale',
                'verification_status' => 'pending_submission',
            ]),
        ]);

        $response = $this->getJson($this->endpoint($channel));

        $response->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.raw_verification_status', 'pending_submission');
        $this->assertNotNull($response->json('data.verify_url'));
    }

    public function test_derives_and_persists_business_id_for_legacy_channel(): void
    {
        [, $user, $channel] = $this->makeChannel(businessId: null, waba: 'WABA_ABC');
        Sanctum::actingAs($user);

        Http::fake([
            // 1) derivar business desde el WABA
            'graph.facebook.com/*/WABA_ABC*' => Http::response([
                'id' => 'WABA_ABC',
                'owner_business_info' => ['id' => 'BIZ_DERIVED', 'name' => 'Owner'],
            ]),
            // 2) leer el estado del business derivado
            'graph.facebook.com/*/BIZ_DERIVED*' => Http::response([
                'id' => 'BIZ_DERIVED',
                'name' => 'Owner',
                'verification_status' => 'not_verified',
            ]),
        ]);

        $response = $this->getJson($this->endpoint($channel));

        $response->assertOk()
            ->assertJsonPath('data.status', 'not_verified')
            ->assertJsonPath('data.business_id', 'BIZ_DERIVED');

        // self-heal: el business_id quedó persistido
        $this->assertSame('BIZ_DERIVED', $channel->whatsappConfig->refresh()->business_id);
    }

    public function test_reports_business_id_missing_when_not_derivable(): void
    {
        [, $user, $channel] = $this->makeChannel(businessId: null, waba: 'WABA_NONE');
        Sanctum::actingAs($user);

        Http::fake([
            'graph.facebook.com/*/WABA_NONE*' => Http::response(['id' => 'WABA_NONE']), // sin owner_business_info
        ]);

        $this->getJson($this->endpoint($channel))
            ->assertOk()
            ->assertJsonPath('data.status', 'business_id_missing');
    }

    public function test_maps_permission_error_to_permission_missing(): void
    {
        [, $user, $channel] = $this->makeChannel(businessId: 'BIZ_NP');
        Sanctum::actingAs($user);

        Http::fake([
            'graph.facebook.com/*/BIZ_NP*' => Http::response([
                'error' => ['message' => 'Permissions error', 'type' => 'OAuthException', 'code' => 200],
            ], 403),
        ]);

        $this->getJson($this->endpoint($channel))
            ->assertOk()
            ->assertJsonPath('data.status', 'permission_missing');
    }

    public function test_workspace_summary_is_verified_and_cached_when_business_is_verified(): void
    {
        [, $user] = $this->makeChannel(businessId: 'BIZ_OK');
        Sanctum::actingAs($user);

        Http::fake([
            'graph.facebook.com/*/BIZ_OK*' => Http::response([
                'id' => 'BIZ_OK',
                'name' => 'Acme',
                'verification_status' => 'verified',
            ]),
        ]);

        $this->getJson('/api/business-verification')->assertOk()->assertJsonPath('data.status', 'verified');
        $this->getJson('/api/business-verification')->assertOk()->assertJsonPath('data.status', 'verified');

        // La segunda carga sale del caché: el sidebar no consulta Graph en cada pedido.
        Http::assertSentCount(1);
    }

    public function test_workspace_summary_reports_the_business_that_needs_most_attention(): void
    {
        [$tenant, $user] = $this->makeChannel(businessId: 'BIZ_OK');
        $this->addChannel($tenant, $user, businessId: 'BIZ_REVIEW');
        $this->addChannel($tenant, $user, businessId: 'BIZ_DRAFT');
        Sanctum::actingAs($user);

        Http::fake([
            'graph.facebook.com/*/BIZ_OK*' => Http::response(['id' => 'BIZ_OK', 'verification_status' => 'verified']),
            'graph.facebook.com/*/BIZ_REVIEW*' => Http::response(['id' => 'BIZ_REVIEW', 'verification_status' => 'pending']),
            'graph.facebook.com/*/BIZ_DRAFT*' => Http::response(['id' => 'BIZ_DRAFT', 'verification_status' => 'pending_submission']),
        ]);

        // "Sin enviar" espera algo del negocio: gana sobre "en revisión" y "verificado".
        $this->getJson('/api/business-verification')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.raw_verification_status', 'pending_submission');
    }

    public function test_workspace_summary_is_null_when_no_business_can_be_read(): void
    {
        [, $user] = $this->makeChannel(businessId: 'BIZ_DENIED');
        Sanctum::actingAs($user);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['code' => 10, 'message' => 'Permission denied']], 403),
        ]);

        $this->getJson('/api/business-verification')->assertOk()->assertJsonPath('data', null);
    }

    public function test_refreshing_a_channel_status_invalidates_the_workspace_summary(): void
    {
        [, $user, $channel] = $this->makeChannel(businessId: 'BIZ_FLIP');
        Sanctum::actingAs($user);

        Http::fakeSequence('graph.facebook.com/*')
            ->push(['id' => 'BIZ_FLIP', 'verification_status' => 'not_verified'])
            ->push(['id' => 'BIZ_FLIP', 'verification_status' => 'verified'])
            ->push(['id' => 'BIZ_FLIP', 'verification_status' => 'verified']);

        $this->getJson('/api/business-verification')->assertJsonPath('data.status', 'not_verified');
        $this->getJson($this->endpoint($channel))->assertJsonPath('data.status', 'verified');
        $this->getJson('/api/business-verification')->assertJsonPath('data.status', 'verified');
    }

    private function endpoint(Channel $channel): string
    {
        return "/api/admin/channels/{$channel->id}/business-verification";
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Channel}
     */
    private function makeChannel(?string $businessId, string $waba = 'WABA_X'): array
    {
        // Sembrar permisos Spatie + rol Owner (gotcha: sin esto, authorize() da 403).
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        $user->assignRole('Owner');

        $config = WhatsAppConfig::create([
            'waba_id' => $waba,
            'phone_number_id' => 'PHONE_1',
            'business_id' => $businessId,
            'bussines_token' => Crypt::encryptString('TOKEN_X'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'whatsapp_config_id' => $config->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp Business',
            'status' => 'active',
        ]);

        return [$tenant, $user, $channel];
    }

    private function addChannel(Tenant $tenant, User $user, string $businessId): Channel
    {
        $config = WhatsAppConfig::create([
            'waba_id' => 'WABA_'.$businessId,
            'phone_number_id' => 'PHONE_'.$businessId,
            'business_id' => $businessId,
            'bussines_token' => Crypt::encryptString('TOKEN_X'),
        ]);

        return Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'whatsapp_config_id' => $config->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp '.$businessId,
            'status' => 'active',
        ]);
    }
}
