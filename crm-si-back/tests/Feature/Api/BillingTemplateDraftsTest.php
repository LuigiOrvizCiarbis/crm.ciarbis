<?php

namespace Tests\Feature\Api;

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Support\BillingTemplateDrafts;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BillingTemplateDraftsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_three_drafts_ready_to_send_to_meta(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/billing-config/template-drafts')->assertOk();

        $data = $response->json('data');
        $this->assertCount(3, $data);
        $this->assertSame(
            [BillingTemplateDrafts::REMINDER, BillingTemplateDrafts::OVERDUE, BillingTemplateDrafts::TRIAL],
            array_column($data, 'key'),
        );

        // Todas UTILITY: un recordatorio de pago es transaccional. Como
        // MARKETING consumiría el cap por usuario y exigiría consentimiento.
        foreach ($data as $draft) {
            $this->assertSame(TemplateCategory::Utility->value, $draft['category']);
            $this->assertNull($draft['template'], 'Sin plantillas creadas todavía.');
        }
    }

    public function test_draft_body_declares_an_example_for_every_variable(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $data = $this->getJson('/api/billing-config/template-drafts')->assertOk()->json('data');

        foreach ($data as $draft) {
            $body = collect($draft['components'])->firstWhere('type', 'BODY');
            preg_match_all('/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*\}\}/', $body['text'], $matches);
            $variables = array_unique($matches[1]);

            $examples = array_column($body['example']['body_text_named_params'], 'param_name');

            // Meta rechaza la creación si falta el ejemplo de alguna variable,
            // y CreateWhatsAppTemplateRequest aborta con 422 antes de llegar.
            $this->assertEqualsCanonicalizing($variables, $examples, "Faltan ejemplos en {$draft['key']}.");
        }
    }

    public function test_draft_names_are_scoped_per_tenant(): void
    {
        [$userA, $tenantA] = $this->createOwner();
        [$userB, $tenantB] = $this->createOwner();

        Sanctum::actingAs($userA);
        $namesA = array_column($this->getJson('/api/billing-config/template-drafts')->json('data'), 'name');

        Sanctum::actingAs($userB);
        $namesB = array_column($this->getJson('/api/billing-config/template-drafts')->json('data'), 'name');

        // Meta rechaza dos plantillas con el mismo nombre en una WABA, que
        // puede estar compartida entre tenants.
        $this->assertEmpty(array_intersect($namesA, $namesB));
        $this->assertStringEndsWith("_t{$tenantA->id}", $namesA[0]);
        $this->assertStringEndsWith("_t{$tenantB->id}", $namesB[0]);
    }

    public function test_footer_is_capped_at_metas_sixty_character_limit(): void
    {
        [$user, $tenant] = $this->createOwner();
        $tenant->update(['name' => str_repeat('A', 120)]);
        Sanctum::actingAs($user);

        $data = $this->getJson('/api/billing-config/template-drafts')->assertOk()->json('data');

        foreach ($data as $draft) {
            $footer = collect($draft['components'])->firstWhere('type', 'FOOTER');
            $this->assertLessThanOrEqual(60, mb_strlen($footer['text']));
        }
    }

    public function test_reports_the_status_of_templates_already_requested(): void
    {
        [$user, $tenant] = $this->createOwner();
        $drafts = BillingTemplateDrafts::all($tenant);

        $this->makeTemplate($tenant, $drafts[0]['name'], TemplateStatus::Pending);
        $this->makeTemplate($tenant, $drafts[1]['name'], TemplateStatus::Rejected, 'Texto poco claro.');

        Sanctum::actingAs($user);
        $data = $this->getJson('/api/billing-config/template-drafts')->assertOk()->json('data');

        $this->assertSame('PENDING', $data[0]['template']['status']);
        $this->assertSame('REJECTED', $data[1]['template']['status']);
        $this->assertSame('Texto poco claro.', $data[1]['template']['rejected_reason']);
        $this->assertNull($data[2]['template'], 'La de trial no se pidió.');
    }

    public function test_requires_billing_manage_permission(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        $member->assignRole('Member');
        Sanctum::actingAs($member);

        // Member tiene billing.view pero no billing.manage: puede ver las
        // tarjetas de cobranzas, no pedir plantillas en nombre del negocio.
        $this->getJson('/api/billing-config/template-drafts')->assertStatus(403);
    }

    private function makeTemplate(Tenant $tenant, string $name, TemplateStatus $status, ?string $reason = null): WhatsAppTemplate
    {
        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 223 555-0101',
            'waba_id' => 'waba-'.uniqid(),
            'bussines_token' => Crypt::encryptString('token'),
        ]);

        return WhatsAppTemplate::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $config->id,
            'external_id' => 'ext-'.uniqid(),
            'name' => $name,
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => $status,
            'rejected_reason' => $reason,
            'components' => [['type' => 'BODY', 'text' => 'Hola {{nombre}}']],
            'synced_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: Tenant} */
    private function createOwner(): array
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');

        return [$user, $tenant];
    }

    private function seedTenantWithRoles(): Tenant
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        return $tenant;
    }
}
