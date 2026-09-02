<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignRoleRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RolePayload;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\JsonResponse;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $tenantId = $request->user()->tenant_id;

        $users = User::query()
            ->where('tenant_id', $tenantId)
            ->with(['roles' => fn ($q) => $q->where('roles.tenant_id', $tenantId)])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->transform($u, $tenantId));

        return response()->json(['data' => $users]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $tenantId = $request->user()->tenant_id;
        $user->load(['roles' => fn ($q) => $q->where('roles.tenant_id', $tenantId)]);

        return response()->json(['data' => $this->transform($user, $tenantId)]);
    }

    public function assignRole(AssignRoleRequest $request, User $user, PermissionRegistrar $registrar): JsonResponse
    {
        $this->authorize('assignRole', $user);

        $actor = $request->user();
        $tenantId = $actor->tenant_id;

        if ((int) $user->tenant_id !== (int) $tenantId) {
            abort(403, 'Usuario fuera del tenant');
        }

        $registrar->setPermissionsTeamId($tenantId);

        $newRoleName = $request->validated('role_name');

        // Owner is identified by tenant.owner_role_id, not by role name —
        // renamed Owner roles (e.g. "Dueño") must be treated as the same
        // privileged role.
        $ownerRoleId = Tenant::query()->whereKey($tenantId)->value('owner_role_id');
        $newRoleId = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $newRoleName)
            ->value('id');

        $actorIsOwner = $ownerRoleId !== null && $actor->roles()
            ->where('roles.id', $ownerRoleId)
            ->where('roles.tenant_id', $tenantId)
            ->exists();
        $targetIsOwner = $ownerRoleId !== null && $user->roles()
            ->where('roles.id', $ownerRoleId)
            ->where('roles.tenant_id', $tenantId)
            ->exists();
        $newRoleIsOwner = $ownerRoleId !== null && (int) $newRoleId === (int) $ownerRoleId;

        if ($newRoleIsOwner && ! $actorIsOwner) {
            abort(403, 'Solo un Owner puede asignar el rol Owner.');
        }

        if ($targetIsOwner && ! $newRoleIsOwner && ! $actorIsOwner) {
            abort(403, 'Solo un Owner puede degradar a otro Owner.');
        }

        // Prevent leaving the tenant without any Owner.
        if ($targetIsOwner && ! $newRoleIsOwner) {
            $remainingOwners = User::query()
                ->where('tenant_id', $tenantId)
                ->where('id', '!=', $user->id)
                ->whereHas('roles', fn ($q) => $q->where('roles.tenant_id', $tenantId)->where('roles.id', $ownerRoleId))
                ->count();

            if ($remainingOwners === 0) {
                abort(422, 'No puedes dejar al tenant sin Owner. Asigna otro Owner antes de cambiar este rol.');
            }
        }

        $user->syncRoles([$newRoleName]);
        $registrar->forgetCachedPermissions();

        $user->load(['roles' => fn ($q) => $q->where('roles.tenant_id', $tenantId)]);

        return response()->json(['data' => $this->transform($user, $tenantId)]);
    }

    public function assignBranch(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $actor = $request->user();
        $tenantId = $actor->tenant_id;

        if ((int) $user->tenant_id !== (int) $tenantId) {
            abort(403, 'Usuario fuera del tenant');
        }

        if (! $actor->can('branches.manage')) {
            abort(403, 'No tienes permiso para asignar sucursales.');
        }

        $validated = $request->validate([
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
        ]);

        $user->forceFill(['branch_id' => $validated['branch_id'] ?? null])->save();
        $user->load(['roles' => fn ($q) => $q->where('roles.tenant_id', $tenantId)]);

        return response()->json(['data' => $this->transform($user, $tenantId)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(User $user, int $tenantId): array
    {
        $role = $user->roles->first();
        $tenant = Tenant::query()->whereKey($tenantId)->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tenant_id' => $user->tenant_id,
            'branch_id' => $user->branch_id,
            'avatar_url' => $user->avatarUrl(),
            'role' => RolePayload::transform($role, $tenant),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
