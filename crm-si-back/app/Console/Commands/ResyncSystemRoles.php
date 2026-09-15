<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\PermissionCatalog;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ResyncSystemRoles extends Command
{
    protected $signature = 'roles:resync-system
                            {--apply : Persist changes. Without this flag the command runs as a dry run}';

    protected $description = 'Grant newly added PermissionCatalog permissions to the seeded system roles (Owner, Admin, Member) of every tenant. Never rewrites a tenant configuration: permissions it added are kept, and ones it removed are not handed back. Dry-run by default.';

    /**
     * Map seeded role name → catalog method that returns the canonical permission set.
     *
     * @var array<string, callable>
     */
    private const ROLE_CATALOG = [
        'Owner' => [PermissionCatalog::class, 'ownerPermissions'],
        'Admin' => [PermissionCatalog::class, 'adminPermissions'],
        'Member' => [PermissionCatalog::class, 'memberPermissions'],
    ];

    public function handle(PermissionRegistrar $registrar): int
    {
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('🔍 DRY RUN — pass --apply to persist changes');
        }

        $tenants = Tenant::query()->orderBy('id')->get();
        if ($tenants->isEmpty()) {
            $this->info('No tenants.');

            return Command::SUCCESS;
        }

        $rows = [];
        $totals = ['granted' => 0, 'in_sync' => 0, 'missing_role' => 0];

        foreach ($tenants as $tenant) {
            $registrar->setPermissionsTeamId($tenant->id);
            $registrar->forgetCachedPermissions();

            $ownerRoleId = $tenant->owner_role_id;
            $unknown = $this->permissionsUnknownToTenant($tenant);

            foreach (self::ROLE_CATALOG as $seededName => $catalogCallback) {
                // For the Owner role we resolve by tenant.owner_role_id so renamed
                // owner roles (e.g. "Dueño") are still tracked. For Admin/Member we
                // resolve by name, but skip any role that is already the tenant's
                // Owner — otherwise a role literally named "Admin" that happens to
                // be the Owner would be processed twice, once per catalog entry.
                if ($seededName === 'Owner') {
                    $role = $ownerRoleId !== null
                        ? Role::query()->where('id', $ownerRoleId)->where('tenant_id', $tenant->id)->first()
                        : null;
                } else {
                    $role = Role::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('name', $seededName)
                        ->when($ownerRoleId !== null, fn ($q) => $q->where('id', '!=', $ownerRoleId))
                        ->first();
                }

                if ($role === null) {
                    $totals['missing_role']++;
                    $rows[] = [$tenant->id, $seededName, '—', '—', 'missing'];

                    continue;
                }

                $expected = $catalogCallback();
                $current = $role->permissions->pluck('name')->all();

                // Only permissions that are new to the whole tenant are granted.
                // A catalog entry this role is missing while another role still
                // holds it was removed by the tenant from Settings → Users, and
                // handing it back on every deploy is exactly the reset they see.
                $grant = array_values(array_intersect(
                    array_diff($expected, $current),
                    $unknown,
                ));
                $tailored = count(array_diff($expected, $current)) - count($grant);

                if ($grant === []) {
                    $totals['in_sync']++;
                    $rows[] = [$tenant->id, $role->name, count($current), '0 / '.$tailored, 'in sync'];

                    continue;
                }

                if ($apply) {
                    $role->givePermissionTo($grant);
                }
                $totals['granted']++;
                $rows[] = [
                    $tenant->id,
                    $role->name,
                    count($current),
                    count($grant).' / '.$tailored,
                    $apply ? 'granted' : 'WOULD GRANT',
                ];
            }
        }

        $this->newLine();
        $this->table(
            ['Tenant', 'Role', 'Catalog perms', 'Missing / Custom', 'Status'],
            $rows,
        );

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Roles in sync', $totals['in_sync']],
                ['Roles '.($apply ? 'granted' : 'to grant'), $totals['granted']],
                ['Roles missing (will not provision here)', $totals['missing_role']],
            ],
        );

        if (! $apply && $totals['granted'] > 0) {
            $this->warn('Re-run with --apply to commit changes.');
        }

        $registrar->forgetCachedPermissions();

        return Command::SUCCESS;
    }

    /**
     * Catalog permissions that no role of this tenant holds yet.
     *
     * Mirrors RoleProvisioner: a permission that shipped after the tenant was
     * seeded appears in none of its roles, whereas one the tenant revoked from
     * Admin or Member is still held elsewhere — so only the former is granted.
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
}
