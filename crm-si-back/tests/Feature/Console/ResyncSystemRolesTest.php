<?php

namespace Tests\Feature\Console;

use App\Models\Tenant;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ResyncSystemRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_grants_a_brand_new_catalog_permission_to_the_seeded_roles(): void
    {
        $tenant = $this->provisionedTenant();

        // A permission that shipped after this tenant was seeded: no role holds it.
        $fresh = $this->permissionNewToTenant($tenant, 'contacts.create');

        $this->artisan('roles:resync-system', ['--apply' => true])->assertSuccessful();

        $this->assertContains($fresh, $this->permissionNames($this->role($tenant, 'Member')));
        $this->assertContains($fresh, $this->permissionNames($this->role($tenant, 'Owner')));
    }

    public function test_keeps_permissions_the_tenant_removed_from_a_seeded_role(): void
    {
        $tenant = $this->provisionedTenant();
        $role = $this->role($tenant, 'Member');

        // The tenant narrowed Member from Settings → Users. Owner still holds it,
        // so this is a deliberate removal, not a permission the tenant never saw.
        $removed = 'contacts.create';
        $role->revokePermissionTo($removed);

        $this->artisan('roles:resync-system', ['--apply' => true])->assertSuccessful();

        $this->assertNotContains($removed, $this->permissionNames($role));
    }

    public function test_keeps_permissions_the_tenant_added_on_top_of_the_catalog(): void
    {
        $tenant = $this->provisionedTenant();
        $role = $this->role($tenant, 'Member');

        // A permission the tenant granted from Settings → Users that is not part
        // of the seeded Member catalog.
        $custom = 'roles.manage';
        $this->assertNotContains($custom, PermissionCatalog::memberPermissions());
        $role->givePermissionTo($custom);

        $this->artisan('roles:resync-system', ['--apply' => true])->assertSuccessful();

        $this->assertContains($custom, $this->permissionNames($role));
    }

    public function test_a_new_permission_lands_without_reverting_a_tailored_role(): void
    {
        $tenant = $this->provisionedTenant();
        $role = $this->role($tenant, 'Member');

        $added = 'roles.manage';
        $removed = 'contacts.create';
        $role->givePermissionTo($added);
        $role->revokePermissionTo($removed);

        $fresh = $this->permissionNewToTenant($tenant, 'messages.update');

        $this->artisan('roles:resync-system', ['--apply' => true])->assertSuccessful();

        $names = $this->permissionNames($role);
        $this->assertContains($fresh, $names, 'new catalog permission should arrive');
        $this->assertContains($added, $names, 'tenant addition should survive');
        $this->assertNotContains($removed, $names, 'tenant removal should survive');
    }

    public function test_dry_run_does_not_persist_anything(): void
    {
        $tenant = $this->provisionedTenant();

        $fresh = $this->permissionNewToTenant($tenant, 'contacts.create');

        $this->artisan('roles:resync-system')->assertSuccessful();

        $this->assertNotContains($fresh, $this->permissionNames($this->role($tenant, 'Member')));
    }

    public function test_a_new_catalog_permission_reaches_every_tenant(): void
    {
        $first = $this->provisionedTenant();
        $second = $this->provisionedTenant();

        $new = 'contacts.create';
        foreach ([$first, $second] as $tenant) {
            $this->permissionNewToTenant($tenant, $new);
        }

        $this->artisan('roles:resync-system', ['--apply' => true])->assertSuccessful();

        foreach ([$first, $second] as $tenant) {
            $this->assertContains($new, $this->permissionNames($this->role($tenant, 'Member')));
        }
    }

    public function test_custom_non_system_roles_are_left_untouched(): void
    {
        $tenant = $this->provisionedTenant();

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $custom = Role::create([
            'name' => 'Vendedor',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
            'is_system' => false,
        ]);
        $custom->syncPermissions(['contacts.view']);

        $this->artisan('roles:resync-system', ['--apply' => true])->assertSuccessful();

        $this->assertSame(['contacts.view'], $this->permissionNames($custom));
    }

    public function test_provisioner_propagates_a_new_permission_without_reverting_a_tailored_role(): void
    {
        // Reproduces the "provision X permission" migrations: they call
        // provisionDefaultRoles() over every existing tenant to hand out a
        // newly shipped permission.
        $tenant = $this->provisionedTenant();
        $role = $this->role($tenant, 'Member');

        $added = 'roles.manage';
        $removed = 'contacts.create';
        $role->givePermissionTo($added);
        $role->revokePermissionTo($removed);

        $fresh = $this->permissionNewToTenant($tenant, 'messages.update');

        app(RoleProvisioner::class)->provisionDefaultRoles($tenant->refresh());

        $names = $this->permissionNames($role);
        $this->assertContains($fresh, $names, 'new catalog permission should arrive');
        $this->assertContains($added, $names, 'tenant addition should survive');
        $this->assertNotContains($removed, $names, 'tenant removal should survive');
    }

    public function test_provisioner_gives_a_brand_new_tenant_the_full_catalog(): void
    {
        $tenant = $this->provisionedTenant();

        $this->assertSame(
            collect(PermissionCatalog::memberPermissions())->sort()->values()->all(),
            $this->permissionNames($this->role($tenant, 'Member')),
        );
    }

    /**
     * Simulate a catalog permission that shipped after the tenant was seeded by
     * stripping it from every role, so no role of the tenant holds it.
     */
    private function permissionNewToTenant(Tenant $tenant, string $permission): string
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        Role::query()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->each(fn (Role $role) => $role->revokePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $permission;
    }

    private function provisionedTenant(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Tenant '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);

        return $tenant->refresh();
    }

    private function role(Tenant $tenant, string $name): Role
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        return Role::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function permissionNames(Role $role): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role->fresh()->permissions->pluck('name')->sort()->values()->all();
    }
}
