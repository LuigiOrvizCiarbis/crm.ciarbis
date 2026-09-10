<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_role_id_is_set_when_tenant_is_provisioned(): void
    {
        [$tenant] = $this->makeOwnerTenant();

        $ownerRole = Role::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Owner')
            ->first();

        $this->assertNotNull($ownerRole);
        $this->assertSame($ownerRole->id, $tenant->fresh()->owner_role_id);
    }

    public function test_default_roles_receive_every_section_access_permission(): void
    {
        [$tenant] = $this->makeOwnerTenant();

        $roles = Role::query()->where('tenant_id', $tenant->id)->with('permissions')->get();

        foreach ($roles as $role) {
            $this->assertEqualsCanonicalizing(
                PermissionCatalog::sectionPermissions(),
                $role->permissions
                    ->pluck('name')
                    ->filter(fn (string $name): bool => str_starts_with($name, 'sections.'))
                    ->values()
                    ->all(),
            );
        }
    }

    public function test_permission_seeder_adds_section_access_without_replacing_custom_role_permissions(): void
    {
        [$tenant] = $this->makeOwnerTenant();
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $role = Role::create([
            'name' => 'Custom',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        $role->givePermissionTo('contacts.view');

        $this->seed(PermissionSeeder::class);

        $permissions = $role->fresh()->permissions->pluck('name')->all();
        $this->assertContains('contacts.view', $permissions);
        $this->assertEqualsCanonicalizing(
            PermissionCatalog::sectionPermissions(),
            array_values(array_filter($permissions, fn (string $permission): bool => str_starts_with($permission, 'sections.'))),
        );
    }

    public function test_renamed_owner_role_still_bypasses_gates(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();

        Role::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Owner')
            ->update(['name' => 'Dueño']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // roles.manage is excluded from the seeded Admin perms; only the
        // Owner gate bypass should grant it.
        $this->assertTrue($owner->fresh()->can('roles.manage'));
    }

    public function test_owner_role_cannot_be_renamed_via_api(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        $ownerRoleId = $tenant->fresh()->owner_role_id;

        Sanctum::actingAs($owner);

        $this->patchJson("/api/roles/{$ownerRoleId}", ['name' => 'Boss'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertSame('Owner', Role::find($ownerRoleId)->name);
    }

    public function test_owner_role_cannot_be_deleted_via_api(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        $ownerRoleId = $tenant->fresh()->owner_role_id;

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/roles/{$ownerRoleId}")
            ->assertForbidden();

        $this->assertNotNull(Role::find($ownerRoleId));
    }

    public function test_cannot_create_role_with_reserved_system_name(): void
    {
        [, $owner] = $this->makeOwnerTenant();

        Sanctum::actingAs($owner);

        $this->postJson('/api/roles', ['name' => 'Admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/roles', ['name' => 'óWnEr'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_admin_cannot_assign_renamed_owner_role_to_others(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        $this->renameOwnerRoleTo($tenant, 'Dueño');

        $admin = $this->makeUserWithRole($tenant, 'Admin');
        $victim = $this->makeUserWithRole($tenant, 'Member');

        Sanctum::actingAs($admin);

        $this->patchJson("/api/users/{$victim->id}/role", ['role_name' => 'Dueño'])
            ->assertForbidden();

        $this->assertSame('Member', $victim->fresh()->roles->first()?->name);
    }

    public function test_admin_cannot_invite_with_renamed_owner_role(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        $this->renameOwnerRoleTo($tenant, 'Dueño');

        $admin = $this->makeUserWithRole($tenant, 'Admin');

        Sanctum::actingAs($admin);

        $this->postJson('/api/invitations', [
            'email' => 'new@example.com',
            'role_name' => 'Dueño',
        ])->assertForbidden();
    }

    public function test_owner_can_still_assign_renamed_owner_role(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        $this->renameOwnerRoleTo($tenant, 'Dueño');

        $target = $this->makeUserWithRole($tenant, 'Member');

        Sanctum::actingAs($owner);

        $this->patchJson("/api/users/{$target->id}/role", ['role_name' => 'Dueño'])
            ->assertOk();

        $this->assertSame('Dueño', $target->fresh()->roles->first()?->name);
    }

    public function test_me_endpoint_exposes_is_owner_flag(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        $this->renameOwnerRoleTo($tenant, 'Dueño');

        $member = $this->makeUserWithRole($tenant, 'Member');

        Sanctum::actingAs($owner);
        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('role.name', 'Dueño')
            ->assertJsonPath('role.is_owner', true);

        Sanctum::actingAs($member);
        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('role.name', 'Member')
            ->assertJsonPath('role.is_owner', false);
    }

    public function test_cannot_demote_last_owner_even_when_renamed(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        $this->renameOwnerRoleTo($tenant, 'Dueño');

        Sanctum::actingAs($owner);

        $this->patchJson("/api/users/{$owner->id}/role", ['role_name' => 'Admin'])
            ->assertStatus(422);

        $this->assertSame('Dueño', $owner->fresh()->roles->first()?->name);
    }

    private function renameOwnerRoleTo(Tenant $tenant, string $newName): void
    {
        Role::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Owner')
            ->update(['name' => $newName]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeUserWithRole(Tenant $tenant, string $roleName): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->syncRoles([$roleName]);

        return $user;
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function makeOwnerTenant(): array
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->syncRoles(['Owner']);

        return [$tenant, $user];
    }
}
