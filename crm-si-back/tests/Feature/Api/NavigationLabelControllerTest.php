<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NavigationLabelControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_replace_navigation_labels_and_user_payload_includes_them(): void
    {
        [$owner, $tenant] = $this->owner();
        Sanctum::actingAs($owner);

        $this->putJson('/api/navigation-labels', [
            'labels' => ['contacts' => '  Clientes  ', 'pipeline' => 'Ventas'],
        ])->assertOk()
            ->assertJsonPath('data.labels.contacts', 'Clientes')
            ->assertJsonPath('data.labels.pipeline', 'Ventas');

        $this->assertSame([
            'contacts' => 'Clientes',
            'pipeline' => 'Ventas',
        ], $tenant->fresh()->navigation_labels);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.tenant.navigation_labels.contacts', 'Clientes');
    }

    public function test_owner_can_restore_all_labels(): void
    {
        [$owner, $tenant] = $this->owner();
        $tenant->update(['navigation_labels' => ['contacts' => 'Clientes']]);
        Sanctum::actingAs($owner);

        $this->putJson('/api/navigation-labels', ['labels' => []])
            ->assertOk()
            ->assertJsonPath('data.labels', []);

        $this->assertNull($tenant->fresh()->navigation_labels);
    }

    public function test_user_without_permission_cannot_update_labels(): void
    {
        $tenant = $this->createTenantWithRoles();
        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        $member->assignRole('Member');
        Sanctum::actingAs($member);

        $this->putJson('/api/navigation-labels', ['labels' => ['contacts' => 'Clientes']])
            ->assertForbidden();
    }

    public function test_default_admin_does_not_receive_the_navigation_label_permission(): void
    {
        $tenant = $this->createTenantWithRoles();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin);

        $this->putJson('/api/navigation-labels', ['labels' => ['contacts' => 'Clientes']])
            ->assertForbidden();
    }

    public function test_labels_validate_known_keys_non_empty_and_max_length(): void
    {
        [$owner] = $this->owner();
        Sanctum::actingAs($owner);

        $this->putJson('/api/navigation-labels', ['labels' => ['unknown' => 'Nombre']])
            ->assertUnprocessable();
        $this->putJson('/api/navigation-labels', ['labels' => ['contacts' => '   ']])
            ->assertUnprocessable();
        $this->putJson('/api/navigation-labels', ['labels' => ['contacts' => str_repeat('x', 31)]])
            ->assertUnprocessable();
    }

    public function test_update_is_scoped_to_the_authenticated_tenant(): void
    {
        [$ownerA, $tenantA] = $this->owner('A');
        [, $tenantB] = $this->owner('B');
        $tenantB->update(['navigation_labels' => ['contacts' => 'Cuentas B']]);
        Sanctum::actingAs($ownerA);

        $this->putJson('/api/navigation-labels', ['labels' => ['contacts' => 'Cuentas A']])
            ->assertOk();

        $this->assertSame(['contacts' => 'Cuentas A'], $tenantA->fresh()->navigation_labels);
        $this->assertSame(['contacts' => 'Cuentas B'], $tenantB->fresh()->navigation_labels);
    }

    /** @return array{User, \App\Models\Tenant} */
    private function owner(string $name = 'Acme'): array
    {
        $tenant = $this->createTenantWithRoles($name);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');

        return [$user, $tenant];
    }
}
