<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleProvisioner
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * Create the two immutable system roles (Owner, Admin) for the given tenant.
     * Idempotent: re-running on an already-provisioned tenant is a no-op.
     */
    public function provisionDefaultRoles(Tenant $tenant): void
    {
        // Permissions are global (team-agnostic). Ensure the catalog exists before
        // syncing roles so a fresh deploy without PermissionSeeder doesn't break
        // workspace registration.
        $this->ensurePermissionsExist();

        DB::transaction(function () use ($tenant): void {
            $this->registrar->setPermissionsTeamId($tenant->id);

            // Resolved before touching any role: once Owner is topped up, every
            // catalog permission is known to the tenant and Admin/Member would
            // see an empty "new" set.
            $unknown = $this->permissionsUnknownToTenant($tenant);

            $owner = $this->provisionRole($tenant, 'Owner', PermissionCatalog::ownerPermissions(), $unknown);

            if ($tenant->owner_role_id === null) {
                $tenant->forceFill(['owner_role_id' => $owner->id])->save();
            }

            $this->provisionRole($tenant, 'Admin', PermissionCatalog::adminPermissions(), $unknown);
            $this->provisionRole($tenant, 'Member', PermissionCatalog::memberPermissions(), $unknown);
        });

        $this->registrar->forgetCachedPermissions();
    }

    /**
     * Create a seeded system role, or top up an existing one.
     *
     * A freshly created role gets the catalog verbatim. An existing role only
     * receives catalog entries that are new to the tenant, never the ones it is
     * merely missing: tenants tailor these roles from Settings → Users, and this
     * method also runs when a new permission is propagated to already-provisioned
     * tenants. Re-applying the full catalog there would hand back every
     * permission the tenant deliberately removed, each time the catalog grows.
     *
     * @param  list<string>  $catalog
     * @param  list<string>  $unknown  catalog permissions no role of this tenant holds yet
     */
    private function provisionRole(Tenant $tenant, string $name, array $catalog, array $unknown): Role
    {
        $role = Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web', 'tenant_id' => $tenant->id],
            ['is_system' => true],
        );

        if (! $role->is_system) {
            $role->forceFill(['is_system' => true])->save();
        }

        if ($role->wasRecentlyCreated) {
            $role->syncPermissions($catalog);

            return $role;
        }

        $grant = array_values(array_intersect(
            array_diff($catalog, $role->permissions->pluck('name')->all()),
            $unknown,
        ));

        if ($grant !== []) {
            $role->givePermissionTo($grant);
        }

        return $role;
    }

    /**
     * Catalog permissions that no role of this tenant holds yet.
     *
     * This is how a genuinely new permission is told apart from one the tenant
     * revoked on purpose: a permission that shipped after the tenant was seeded
     * appears nowhere in it, while a revoked one is still held by some other
     * role (Owner keeps the full catalog unless the tenant pruned it too).
     *
     * @return list<string>
     */
    private function permissionsUnknownToTenant(Tenant $tenant): array
    {
        $held = Role::query()
            ->where('tenant_id', $tenant->id)
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->all();

        return array_values(array_diff(PermissionCatalog::all(), $held));
    }

    private function ensurePermissionsExist(): void
    {
        $this->registrar->setPermissionsTeamId(null);
        // Reset the cached permission collection so subsequent inserts don't
        // throw PermissionAlreadyExists after the DB was wiped (e.g. tests).
        $this->registrar->forgetCachedPermissions();

        $created = false;
        foreach (PermissionCatalog::all() as $name) {
            $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();
            if ($permission === null) {
                Permission::create(['name' => $name, 'guard_name' => 'web']);
                $created = true;
            }
        }

        if ($created) {
            $this->registrar->forgetCachedPermissions();
        }
    }
}
