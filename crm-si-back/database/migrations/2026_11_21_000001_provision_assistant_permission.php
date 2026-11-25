<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Provisiona assistant.use en los tenants existentes.
 *
 * Incluye a Member además de Admin/Owner: el asistente interno es para todo el
 * equipo, y sus herramientas solo leen lo que cada rol ya puede ver.
 */
return new class extends Migration
{
    private const PERMISSION = 'assistant.use';

    private const ROLES = ['Admin', 'Member'];

    public function up(): void
    {
        /** @var PermissionRegistrar $registrar */
        $registrar = app(PermissionRegistrar::class);
        // Los permisos son globales; los pivots role/permission son por team.
        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();

        $permission = Permission::findOrCreate(self::PERMISSION, 'web');

        Tenant::query()->select(['id', 'owner_role_id'])->each(function (Tenant $tenant) use ($permission, $registrar): void {
            $registrar->setPermissionsTeamId($tenant->id);

            $roles = Role::query()
                ->where('tenant_id', $tenant->id)
                ->where(function ($query) use ($tenant): void {
                    $query->whereIn('name', self::ROLES)
                        ->orWhere('id', $tenant->owner_role_id);
                })
                ->get();

            foreach ($roles as $role) {
                $role->givePermissionTo($permission);
            }
        });

        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        /** @var PermissionRegistrar $registrar */
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);

        $permission = Permission::query()
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->first();

        if (! $permission) {
            return;
        }

        Tenant::query()->select(['id', 'owner_role_id'])->each(function (Tenant $tenant) use ($permission, $registrar): void {
            $registrar->setPermissionsTeamId($tenant->id);
            Role::query()
                ->where('tenant_id', $tenant->id)
                ->where(function ($query) use ($tenant): void {
                    $query->whereIn('name', self::ROLES)
                        ->orWhere('id', $tenant->owner_role_id);
                })
                ->get()
                ->each(fn (Role $role) => $role->revokePermissionTo($permission));
        });

        $registrar->setPermissionsTeamId(null);
        if (! DB::table('role_has_permissions')->where('permission_id', $permission->id)->exists()) {
            $permission->delete();
        }
        $registrar->forgetCachedPermissions();
    }
};
