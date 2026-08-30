<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La decisión del usuario fue resolver la elegibilidad de grupos también en
 * el onboarding (no sólo a pedido desde el front), para que ya esté
 * cacheada cuando se abre Chats. Test separado porque
 * WhatsAppOnboardingTest fuerza graph_version=v21.0 en su setUp() y sus
 * fakes no siempre exponen el resultado real de is_official_business_account.
 */
class WhatsAppOnboardingGroupsEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/admin/channels/whatsapp-auth';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.facebook.app_id', 'test-app-id');
        config()->set('services.facebook.app_secret', 'test-app-secret');
    }

    private function fakeSuccessfulOnboarding(array $phoneNumberFields): void
    {
        Http::fake(function ($request) use ($phoneNumberFields) {
            $url = (string) $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'TOKEN_AAA'], 200);
            }
            if (str_contains($url, '/WABA_AAA/phone_numbers')) {
                return Http::response(['data' => [['id' => 'PHONE_111', 'display_phone_number' => '+54 11 1111-1111', 'verified_name' => 'Acme']]], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, '/PHONE_111/register')) {
                return Http::response(['success' => true], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, '/WABA_AAA/subscribed_apps')) {
                return Http::response(['success' => true], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/WABA_AAA/subscribed_apps')) {
                return Http::response(['data' => [['whatsapp_business_api_data' => ['id' => 'test-app-id']]]], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/PHONE_111?fields=')) {
                return Http::response($phoneNumberFields, 200);
            }
            if (str_contains($url, '/PHONE_111/smb_app_data')) {
                return Http::response(['request_id' => 'REQ_123'], 200);
            }

            return Http::response(['error' => ['message' => "unmapped url {$url}"]], 404);
        });
    }

    public function test_onboarding_persists_eligible_status_for_an_official_business_account(): void
    {
        [, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeSuccessfulOnboarding([
            'is_on_biz_app' => false,
            'is_official_business_account' => true,
            'platform_type' => 'CLOUD_API',
        ]);

        $response = $this->postJson(self::ENDPOINT, $this->payload());
        $response->assertOk()->assertJsonPath('success', true);

        $config = WhatsAppConfig::first();
        $this->assertNotNull($config);
        $this->assertSame(WhatsAppConfig::GROUPS_ELIGIBLE, $config->groups_eligibility_status);
        $this->assertNotNull($config->groups_eligibility_checked_at);
    }

    public function test_onboarding_persists_coexistence_status_and_does_not_fail(): void
    {
        [, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->fakeSuccessfulOnboarding([
            'is_on_biz_app' => true,
            'is_official_business_account' => true,
            'platform_type' => 'SMB_APP',
        ]);

        $response = $this->postJson(self::ENDPOINT, $this->payload());
        $response->assertOk()->assertJsonPath('success', true);

        $config = WhatsAppConfig::first();
        $this->assertSame(WhatsAppConfig::GROUPS_ON_BIZ_APP, $config->groups_eligibility_status);
    }

    private function payload(): array
    {
        return [
            'code' => 'CODE_AAA',
            'name' => 'WhatsApp Business',
            'data' => [
                'waba_id' => 'WABA_AAA',
                'phone_number_id' => 'PHONE_111',
            ],
        ];
    }

    /** @return array{0: Tenant, 1: User} */
    private function createTenantAndUser(): array
    {
        $tenant = $this->createTenantWithRoles('Acme '.uniqid());
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');

        return [$tenant, $user];
    }
}
