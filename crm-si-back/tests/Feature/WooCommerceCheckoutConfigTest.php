<?php

namespace Tests\Feature;

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Models\WooCommerceConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WooCommerceCheckoutConfigTest extends TestCase
{
    use RefreshDatabase;

    // IP pública literal: PublicUrlGuard no necesita DNS real.
    private const STORE_URL = 'https://93.184.216.34';

    private WooCommerceConfig $config;

    private WhatsAppTemplate $paidTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');

        $waConfig = WhatsAppConfig::create([
            'phone_number_id' => '123456789',
            'display_phone_number' => '+54 9 11 0000-0000',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);
        $this->paidTemplate = WhatsAppTemplate::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $waConfig->id,
            'external_id' => '9999999999',
            'name' => 'pago_confirmado',
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'parameter_format' => 'NAMED',
            'components' => [
                ['type' => 'BODY', 'text' => 'Recibimos el pago del pedido {{pedido}} por {{total}}.'],
            ],
            'synced_at' => now(),
        ]);

        $this->config = new WooCommerceConfig([
            'tenant_id' => $tenant->id,
            'store_url' => self::STORE_URL,
            'enabled' => true,
            'whatsapp_checkout_enabled' => true,
            'paid_template_id' => $this->paidTemplate->id,
        ]);
        $this->config->setEncryptedConsumerKey('ck_test');
        $this->config->setEncryptedConsumerSecret('cs_test');
        $this->config->save();

        Sanctum::actingAs($user);
    }

    public function test_missing_items_are_codes_for_the_ui_to_translate(): void
    {
        $this->getJson('/api/woocommerce-config')
            ->assertOk()
            ->assertJsonPath('data.checkout_missing', ['mercado_pago', 'meta_catalog_id', 'flow_installed']);
    }

    public function test_a_saved_template_that_is_no_longer_approved_does_not_block_other_changes(): void
    {
        $this->paidTemplate->update(['status' => TemplateStatus::Paused]);

        $this->putJson('/api/woocommerce-config', [
            'store_url' => self::STORE_URL,
            'enabled' => true,
            'meta_catalog_id' => 'catalog-1',
        ])
            ->assertOk()
            ->assertJsonPath('data.meta_catalog_id', 'catalog-1')
            ->assertJsonPath('data.paid_template_id', $this->paidTemplate->id);
    }

    public function test_choosing_a_template_that_is_not_approved_names_which_template_failed(): void
    {
        $this->paidTemplate->update(['status' => TemplateStatus::Paused]);

        $this->putJson('/api/woocommerce-config', [
            'store_url' => self::STORE_URL,
            'enabled' => true,
            'paid_template_id' => $this->paidTemplate->id,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.paid_template_id.0', 'La plantilla de pago confirmado ya no está aprobada. Elegí otra.');
    }
}
