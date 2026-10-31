<?php

use App\Models\Tenant;
use App\Support\RoleProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Propaga billing.view/billing.manage (PermissionCatalog) a los roles Owner/
 * Admin de los tenants ya existentes. provisionDefaultRoles crea la fila
 * global del permiso si falta (ensurePermissionsExist) y sincroniza el
 * catálogo completo en cada rol — no solo lo nuevo — así que es seguro
 * volver a correrlo sobre tenants que ya tienen sus roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Tenant::query()->each(fn (Tenant $tenant) => app(RoleProvisioner::class)->provisionDefaultRoles($tenant));
    }

    public function down(): void
    {
        // Permission removal is intentionally omitted because roles may reference it.
    }
};
