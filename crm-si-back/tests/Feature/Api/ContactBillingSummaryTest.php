<?php

namespace Tests\Feature\Api;

use App\Enums\ContactFieldType;
use App\Models\BillingConfig;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ContactBillingSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_omits_billing_keys_when_no_config_exists(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/contacts/summary')->assertOk();

        $this->assertArrayNotHasKey('billing_al_dia', $response->json());
        $this->assertArrayNotHasKey('billing_por_vencer', $response->json());
        $this->assertArrayNotHasKey('billing_vencido', $response->json());
    }

    public function test_summary_omits_billing_keys_when_config_is_disabled(): void
    {
        [$user] = $this->createOwner();
        $this->createBillingFields($user->tenant_id);
        $this->createBillingConfig($user->tenant_id, enabled: false);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/contacts/summary')->assertOk();

        $this->assertArrayNotHasKey('billing_al_dia', $response->json());
    }

    public function test_summary_buckets_contacts_by_status_and_due_date(): void
    {
        [$user] = $this->createOwner();
        $this->createBillingFields($user->tenant_id);
        $this->createBillingConfig($user->tenant_id);

        $this->createContact($user->tenant_id, 'al_dia', now()->addDays(20)->format('Y-m-d'));
        $this->createContact($user->tenant_id, 'impago', now()->addDays(3)->format('Y-m-d')); // por vencer
        $this->createContact($user->tenant_id, 'impago', now()->subDays(2)->format('Y-m-d')); // vencido
        $this->createContact($user->tenant_id, 'en_prueba', now()->addDays(1)->format('Y-m-d')); // por vencer

        Sanctum::actingAs($user);

        $this->getJson('/api/contacts/summary')
            ->assertOk()
            ->assertJsonPath('billing_al_dia', 1)
            ->assertJsonPath('billing_por_vencer', 2)
            ->assertJsonPath('billing_vencido', 1);
    }

    public function test_summary_excludes_today_from_both_por_vencer_and_vencido_double_counting(): void
    {
        [$user] = $this->createOwner();
        $this->createBillingFields($user->tenant_id);
        $this->createBillingConfig($user->tenant_id);

        $this->createContact($user->tenant_id, 'impago', now()->format('Y-m-d'));

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/contacts/summary')->assertOk();

        // Vence hoy: cuenta en por_vencer (rango hoy..+7), no en vencido.
        $this->assertSame(1, $response->json('billing_por_vencer'));
        $this->assertSame(0, $response->json('billing_vencido'));
    }

    public function test_summary_isolates_by_tenant(): void
    {
        [$user] = $this->createOwner();
        $this->createBillingFields($user->tenant_id);
        $this->createBillingConfig($user->tenant_id);
        $this->createContact($user->tenant_id, 'al_dia', now()->addDays(1)->format('Y-m-d'));

        $otherTenant = $this->seedTenantWithRoles();
        $this->createBillingFields($otherTenant->id);
        $this->createBillingConfig($otherTenant->id);
        $this->createContact($otherTenant->id, 'al_dia', now()->addDays(1)->format('Y-m-d'));
        $this->createContact($otherTenant->id, 'al_dia', now()->addDays(1)->format('Y-m-d'));

        Sanctum::actingAs($user);

        $this->getJson('/api/contacts/summary')
            ->assertOk()
            ->assertJsonPath('billing_al_dia', 1);
    }

    private function createContact(int $tenantId, string $estado, string $vencimiento): Contact
    {
        return Contact::create([
            'tenant_id' => $tenantId,
            'name' => 'Contacto '.uniqid(),
            'source' => 'manual',
            'custom_data' => ['estado' => $estado, 'vencimiento' => $vencimiento],
        ]);
    }

    private function createBillingConfig(int $tenantId, bool $enabled = true): BillingConfig
    {
        return BillingConfig::create([
            'tenant_id' => $tenantId,
            'enabled' => $enabled,
            'due_date_field_key' => 'vencimiento',
            'status_field_key' => 'estado',
            'overdue_cycles_field_key' => 'ciclos_impagos',
            'cycle_unit' => 'months',
            'cycle_length' => 1,
            'timezone' => 'America/Argentina/Buenos_Aires',
            'grace_days' => 3,
        ]);
    }

    private function createBillingFields(int $tenantId): void
    {
        ContactField::create([
            'tenant_id' => $tenantId,
            'key' => 'vencimiento',
            'label' => 'Vencimiento',
            'type' => ContactFieldType::Date,
            'display_order' => 0,
        ]);
        ContactField::create([
            'tenant_id' => $tenantId,
            'key' => 'estado',
            'label' => 'Estado',
            'type' => ContactFieldType::Select,
            'options' => ['choices' => ['al_dia', 'impago', 'en_prueba']],
            'display_order' => 1,
        ]);
        ContactField::create([
            'tenant_id' => $tenantId,
            'key' => 'ciclos_impagos',
            'label' => 'Ciclos impagos',
            'type' => ContactFieldType::Number,
            'display_order' => 2,
        ]);
    }

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
