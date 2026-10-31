<?php

namespace Tests\Feature\Api;

use App\Enums\ContactFieldType;
use App\Models\BillingConfig;
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

class BillingConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_billing_config(): void
    {
        [$user] = $this->createOwner();
        $this->createBillingFields($user->tenant_id);
        Sanctum::actingAs($user);

        $this->putJson('/api/billing-config', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.due_date_field_key', 'vencimiento')
            ->assertJsonPath('data.status_field_key', 'estado')
            ->assertJsonPath('data.overdue_cycles_field_key', 'ciclos_impagos')
            ->assertJsonPath('data.grace_days', 3);

        $this->assertDatabaseHas('billing_configs', [
            'tenant_id' => $user->tenant_id,
            'due_date_field_key' => 'vencimiento',
        ]);
    }

    public function test_show_returns_defaults_when_no_config_exists(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $this->getJson('/api/billing-config')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.due_date_field_key', null);
    }

    public function test_update_is_idempotent_and_reuses_existing_row(): void
    {
        [$user] = $this->createOwner();
        $this->createBillingFields($user->tenant_id);
        Sanctum::actingAs($user);

        $this->putJson('/api/billing-config', $this->payload())->assertOk();
        $this->putJson('/api/billing-config', $this->payload(['grace_days' => 5]))
            ->assertOk()
            ->assertJsonPath('data.grace_days', 5);

        $this->assertSame(1, BillingConfig::where('tenant_id', $user->tenant_id)->count());
    }

    public function test_rejects_due_date_field_key_of_wrong_type(): void
    {
        [$user] = $this->createOwner();
        $this->createBillingFields($user->tenant_id);
        // Campo de texto en vez de Date.
        ContactField::create([
            'tenant_id' => $user->tenant_id,
            'key' => 'notas',
            'label' => 'Notas',
            'type' => ContactFieldType::Text,
            'display_order' => 3,
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/billing-config', $this->payload(['due_date_field_key' => 'notas']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_date_field_key']);
    }

    public function test_rejects_due_date_field_key_that_does_not_exist(): void
    {
        [$user] = $this->createOwner();
        $this->createBillingFields($user->tenant_id);
        Sanctum::actingAs($user);

        $this->putJson('/api/billing-config', $this->payload(['due_date_field_key' => 'inexistente']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_date_field_key']);
    }

    public function test_rejects_status_field_missing_required_choices(): void
    {
        [$user] = $this->createOwner();
        $this->createBillingFields($user->tenant_id);
        ContactField::create([
            'tenant_id' => $user->tenant_id,
            'key' => 'estado_incompleto',
            'label' => 'Estado incompleto',
            'type' => ContactFieldType::Select,
            'options' => ['choices' => ['al_dia', 'impago']], // falta en_prueba
            'display_order' => 4,
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/billing-config', $this->payload(['status_field_key' => 'estado_incompleto']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status_field_key']);
    }

    public function test_rejects_externally_managed_field_of_wrong_type(): void
    {
        [$user] = $this->createOwner();
        $this->createBillingFields($user->tenant_id);
        ContactField::create([
            'tenant_id' => $user->tenant_id,
            'key' => 'texto_libre',
            'label' => 'Texto libre',
            'type' => ContactFieldType::Text,
            'display_order' => 5,
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/billing-config', $this->payload(['externally_managed_field_key' => 'texto_libre']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['externally_managed_field_key']);
    }

    public function test_member_without_permission_is_rejected(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $this->createBillingFields($tenant->id);
        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        $member->assignRole('Member');
        Sanctum::actingAs($member);

        $this->putJson('/api/billing-config', $this->payload())->assertStatus(403);
    }

    public function test_member_can_view_but_not_manage(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $this->createBillingFields($tenant->id);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $owner->assignRole('Owner');
        Sanctum::actingAs($owner);
        $this->putJson('/api/billing-config', $this->payload())->assertOk();

        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        $member->assignRole('Member');
        Sanctum::actingAs($member);

        $this->getJson('/api/billing-config')
            ->assertOk()
            ->assertJsonPath('data.due_date_field_key', 'vencimiento');
        $this->putJson('/api/billing-config', $this->payload(['grace_days' => 10]))->assertStatus(403);
    }

    public function test_cross_tenant_isolation(): void
    {
        [$user] = $this->createOwner();
        $this->createBillingFields($user->tenant_id);
        Sanctum::actingAs($user);
        $this->putJson('/api/billing-config', $this->payload())->assertOk();

        $otherTenant = $this->seedTenantWithRoles();
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherUser->assignRole('Owner');
        Sanctum::actingAs($otherUser);

        $this->getJson('/api/billing-config')
            ->assertOk()
            ->assertJsonPath('data.due_date_field_key', null);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'due_date_field_key' => 'vencimiento',
            'status_field_key' => 'estado',
            'overdue_cycles_field_key' => 'ciclos_impagos',
            'externally_managed_field_key' => null,
            'cycle_unit' => 'months',
            'cycle_length' => 1,
            'timezone' => 'America/Argentina/Buenos_Aires',
            'grace_days' => 3,
        ], $overrides);
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
