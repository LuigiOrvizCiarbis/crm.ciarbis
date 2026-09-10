<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Permissions are global (team-agnostic). Only roles + pivots are team-scoped.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        foreach (PermissionCatalog::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Los accesos a secciones se introducen como una capacidad base de la
        // navegación. Al ejecutar el seeder en un workspace existente se
        // agregan sin reemplazar los permisos personalizados de cada rol.
        $sectionPermissionIds = Permission::query()
            ->whereIn('name', PermissionCatalog::sectionPermissions())
            ->where('guard_name', 'web')
            ->pluck('id');

        foreach (DB::table('roles')->orderBy('id')->cursor() as $role) {
            foreach ($sectionPermissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $role->id,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
