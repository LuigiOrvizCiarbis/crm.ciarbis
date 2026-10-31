<?php

namespace Tests;

use App\Models\Tenant;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Crea un tenant con los roles de sistema (Owner/Admin/…) ya provisionados
     * y deja el registrar apuntando a su team.
     *
     * Las policies autorizan por permisos de Spatie, no por el enum `role` del
     * usuario: un tenant creado con `Tenant::create()` a secas no tiene roles,
     * y cualquier request de sus usuarios responde 403.
     */
    protected function createTenantWithRoles(string $name = 'Acme'): Tenant
    {
        $registrar = app(PermissionRegistrar::class);

        // El catálogo de permisos es global: se crea fuera de cualquier team.
        $registrar->setPermissionsTeamId(null);

        $tenant = Tenant::create(['name' => $name]);

        // provisionDefaultRoles() se encarga de crear el catálogo si falta.
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);

        $registrar->setPermissionsTeamId($tenant->id);
        $registrar->forgetCachedPermissions();

        return $tenant;
    }
}
