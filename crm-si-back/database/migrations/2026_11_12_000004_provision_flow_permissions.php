<?php

use App\Models\Tenant;
use App\Support\RoleProvisioner;
use Illuminate\Database\Migrations\Migration;

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
