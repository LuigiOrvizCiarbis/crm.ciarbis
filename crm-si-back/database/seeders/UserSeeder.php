<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Models\TenantMembership;
use App\Support\RoleProvisioner;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['name' => 'Demo Company'],
            ['timezone' => 'UTC'],
        );

        // Provision Owner/Admin/Member roles for this tenant before assigning them.
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $this->seedUser($tenant, 'admin@demo.com', 'Admin User', 'password', 'Owner');
        $this->seedUser($tenant, 'vendedor1@demo.com', 'Vendedor 1', 'password', 'Member');
        $this->seedUser($tenant, 'vendedor2@demo.com', 'Vendedor 2', 'password', 'Member');
        $this->seedUser($tenant, 'reviewer@sicrmapp.com', 'Meta Reviewer', 'Reviewers2026', 'Admin', verified: true);

        $this->command->info('✅ Tenant, roles y usuarios listos');
        $this->command->info('📧 Owner:    admin@demo.com         | password: password');
        $this->command->info('📧 Admin:    reviewer@sicrmapp.com   | password: Reviewers2026');
        $this->command->info('📧 Members:  vendedor1@demo.com / vendedor2@demo.com | password: password');
    }

    private function seedUser(Tenant $tenant, string $email, string $name, string $password, string $roleName, bool $verified = false): void
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'tenant_id' => $tenant->id,
                'name' => $name,
                'password' => bcrypt($password),
                'email_verified_at' => $verified ? now() : null,
            ],
        );

        // If the user exists but somehow belongs to another tenant or has stale
        // attributes, refuse to overwrite — the seeder is for fresh dev DBs.
        $user->syncRoles([$roleName]);
        TenantMembership::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['joined_at' => $user->created_at ?? now()],
        );
    }
}
