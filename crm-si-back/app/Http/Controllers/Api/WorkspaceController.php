<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WorkspaceAuditEvent;
use App\Support\ProductFieldProvisioner;
use App\Support\RoleProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberships = $user->activeMemberships()->with(['tenant.plan', 'branch'])->orderByDesc('joined_at')->get();
        $roles = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)->where('model_has_roles.model_type', User::class)
            ->pluck('roles.name', 'model_has_roles.tenant_id');

        return response()->json(['data' => $memberships->map(fn (TenantMembership $membership) => [
            'id' => $membership->tenant_id,
            'name' => $membership->tenant->name,
            'role' => $roles->get($membership->tenant_id),
            'branch_id' => $membership->branch_id,
            'branch_name' => $membership->branch?->name,
            'plan' => $membership->tenant->plan ? ['key' => $membership->tenant->plan->key, 'name' => $membership->tenant->plan->name] : null,
            'trial_ends_at' => $membership->tenant->trial_ends_at,
            'joined_at' => $membership->joined_at,
        ])]);
    }

    public function store(Request $request, PermissionRegistrar $registrar): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasVerifiedEmail(), 403, 'Verificá tu email antes de crear un workspace.');
        $validated = $request->validate(['name' => ['required', 'string', 'min:2', 'max:100']]);
        $key = 'workspace-create:'.$user->id;
        abort_if(cache()->has($key), 429, 'Esperá antes de crear otro workspace.');

        $tenant = DB::transaction(function () use ($validated, $user, $registrar): Tenant {
            $tenant = Tenant::create(['name' => trim($validated['name'])]);
            app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
            app(ProductFieldProvisioner::class)->seedDefaults($tenant);
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'joined_at' => now()]);
            $registrar->setPermissionsTeamId($tenant->id);
            $user->syncRoles(['Owner']);
            return $tenant;
        });

        cache()->put($key, true, now()->addHour());
        $this->recordAudit($tenant->id, $user->id, 'workspace.created');
        return response()->json(['data' => ['id' => $tenant->id, 'name' => $tenant->name]], 201);
    }

    public function update(Request $request, Tenant $workspace): JsonResponse
    {
        $this->assertOwner($request->user(), $workspace->id);
        $validated = $request->validate(['name' => ['required', 'string', 'min:2', 'max:100']]);
        $oldName = $workspace->name;
        $workspace->update(['name' => trim($validated['name'])]);
        $this->recordAudit($workspace->id, $request->user()->id, 'workspace.renamed', ['from' => $oldName, 'to' => $workspace->name]);
        return response()->json(['data' => ['id' => $workspace->id, 'name' => $workspace->name]]);
    }

    public function destroy(Request $request, Tenant $workspace): JsonResponse
    {
        $this->assertOwner($request->user(), $workspace->id);
        $request->validate(['name_confirmation' => ['required', 'string'], 'password' => ['required', 'string']]);
        abort_unless(hash_equals($workspace->name, $request->string('name_confirmation')->toString()), 422, 'El nombre no coincide.');
        abort_unless(Hash::check($request->string('password')->toString(), $request->user()->password), 422, 'La contraseña es incorrecta.');

        $workspace->update(['status' => 'pending_deletion', 'deactivated_at' => now(), 'deletion_scheduled_at' => now()->addDays(30)]);
        $this->recordAudit($workspace->id, $request->user()->id, 'workspace.deletion_scheduled', ['purge_at' => $workspace->deletion_scheduled_at]);
        return response()->json(['message' => 'Workspace desactivado. Podés restaurarlo durante 30 días.']);
    }

    public function restore(Request $request, Tenant $workspace): JsonResponse
    {
        $membership = TenantMembership::active()->where('tenant_id', $workspace->id)->where('user_id', $request->user()->id)->firstOrFail();
        $this->assertOwner($request->user(), $workspace->id);
        abort_unless($workspace->status === 'pending_deletion' && $workspace->deletion_scheduled_at?->isFuture(), 422, 'Este workspace ya no se puede restaurar.');
        $workspace->update(['status' => 'active', 'deactivated_at' => null, 'deletion_scheduled_at' => null]);
        $this->recordAudit($workspace->id, $request->user()->id, 'workspace.restored');
        return response()->json(['message' => 'Workspace restaurado.']);
    }

    public function leave(Request $request, Tenant $workspace): JsonResponse
    {
        $membership = TenantMembership::active()->where('tenant_id', $workspace->id)->where('user_id', $request->user()->id)->firstOrFail();
        $this->assertNotLastOwner($workspace->id, $request->user()->id);
        $membership->update(['removed_at' => now(), 'branch_id' => null]);
        $this->removeTenantAccess($workspace->id, $request->user()->id);
        $this->recordAudit($workspace->id, $request->user()->id, 'membership.left', ['subject_user_id' => $request->user()->id]);
        return response()->json(['message' => 'Saliste del workspace.']);
    }

    public function removeMember(Request $request, Tenant $workspace, int $user): JsonResponse
    {
        $target = User::withoutGlobalScopes()->findOrFail($user);
        $targetOwnerRole = $workspace->owner_role_id;
        $targetIsOwner = $targetOwnerRole && DB::table('model_has_roles')->where('tenant_id', $workspace->id)->where('role_id', $targetOwnerRole)->where('model_id', $target->id)->where('model_type', User::class)->exists();
        abort_unless($this->canManageMembersInWorkspace($request->user(), $workspace->id), 403, 'No tenés permiso para remover miembros.');
        if ($targetIsOwner) $this->assertOwner($request->user(), $workspace->id);
        $membership = TenantMembership::active()->where('tenant_id', $workspace->id)->where('user_id', $target->id)->firstOrFail();
        $this->assertNotLastOwner($workspace->id, $target->id);
        $membership->update(['removed_at' => now(), 'branch_id' => null]);
        $this->removeTenantAccess($workspace->id, $target->id);
        $this->recordAudit($workspace->id, $request->user()->id, 'membership.removed', ['subject_user_id' => $target->id]);
        return response()->json(['message' => 'Miembro removido.']);
    }

    public function audit(Request $request, Tenant $workspace): JsonResponse
    {
        $this->assertOwner($request->user(), $workspace->id);
        $events = WorkspaceAuditEvent::query()->where('tenant_id', $workspace->id)->latest()->paginate(50);
        return response()->json($events);
    }

    private function assertOwner(User $user, int $tenantId): void
    {
        abort_unless($this->isOwner($user, $tenantId), 403, 'Sólo un Owner puede realizar esta acción.');
    }

    private function isOwner(User $user, int $tenantId): bool
    {
        $ownerRoleId = Tenant::whereKey($tenantId)->value('owner_role_id');
        return (bool) ($ownerRoleId && DB::table('model_has_roles')->where('tenant_id', $tenantId)->where('role_id', $ownerRoleId)->where('model_id', $user->id)->where('model_type', User::class)->exists());
    }

    private function canManageMembersInWorkspace(User $user, int $tenantId): bool
    {
        if (! TenantMembership::active()->where('tenant_id', $tenantId)->where('user_id', $user->id)->exists()) {
            return false;
        }

        if ($this->isOwner($user, $tenantId)) {
            return true;
        }

        // `can()` uses Spatie's current team resolver. Rebind it to the target
        // workspace so an Admin permission in another open tab cannot authorize
        // a membership mutation here.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $user->can('users.deactivate');
    }

    private function assertNotLastOwner(int $tenantId, int $userId): void
    {
        $ownerRoleId = Tenant::whereKey($tenantId)->value('owner_role_id');
        if (! $ownerRoleId) return;
        $isOwner = DB::table('model_has_roles')->where('tenant_id', $tenantId)->where('role_id', $ownerRoleId)->where('model_id', $userId)->where('model_type', User::class)->exists();
        if ($isOwner) {
            $remaining = DB::table('model_has_roles')->where('tenant_id', $tenantId)->where('role_id', $ownerRoleId)->where('model_type', User::class)->where('model_id', '!=', $userId)->count();
            abort_if($remaining === 0, 422, 'No puedes dejar el workspace sin Owner.');
        }
    }

    private function removeTenantAccess(int $tenantId, int $userId): void
    {
        DB::table('channel_user')->where('user_id', $userId)->whereIn('channel_id', fn ($query) => $query->select('id')->from('channels')->where('tenant_id', $tenantId))->delete();
        DB::table('conversation_user')->where('user_id', $userId)->whereIn('conversation_id', fn ($query) => $query->select('id')->from('conversations')->where('tenant_id', $tenantId))->delete();
        DB::table('conversations')->where('tenant_id', $tenantId)->where('assigned_to', $userId)->whereIn('status', ['open', 'pending'])->update(['assigned_to' => null]);
        DB::table('opportunities')->where('tenant_id', $tenantId)->where('assigned_to', $userId)->where('status', 'open')->update(['assigned_to' => null]);
        DB::table('tasks')->where('tenant_id', $tenantId)->where('assigned_to', $userId)->whereNotIn('status', ['hecho', 'cancelado'])->update(['assigned_to' => null]);
        DB::table('instagram_comments')->where('tenant_id', $tenantId)->where('assigned_to', $userId)->whereIn('status', ['new', 'in_progress'])->update(['assigned_to' => null]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        User::withoutGlobalScopes()->find($userId)?->syncRoles([]);
    }

    private function recordAudit(int $tenantId, ?int $actorId, string $event, array $metadata = []): void
    {
        $subjectId = $metadata['subject_user_id'] ?? null;
        unset($metadata['subject_user_id']);
        WorkspaceAuditEvent::create(['tenant_id' => $tenantId, 'actor_user_id' => $actorId, 'subject_user_id' => $subjectId, 'event' => $event, 'metadata' => $metadata]);
    }
}
