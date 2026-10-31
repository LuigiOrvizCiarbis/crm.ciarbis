<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WorkspaceAuditEvent;
use App\Notifications\InvitationNotification;
use App\Support\RolePayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\JsonResponse;

class InvitationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invitation::class);

        $invitations = Invitation::with('invitedBy:id,name,email')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $invitations]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Invitation::class);

        $actor = $request->user();
        $tenantId = $actor->tenant_id;

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role_name' => [
                'required',
                'string',
                'max:100',
                Rule::exists('roles', 'name')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
        ]);

        // Only an Owner may issue invitations for the Owner role. The Owner
        // role is identified by tenant.owner_role_id, not by name — renamed
        // owner roles must be guarded too.
        $ownerRoleId = Tenant::query()->whereKey($tenantId)->value('owner_role_id');
        $invitedRoleId = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $validated['role_name'])
            ->value('id');
        $invitingOwner = $ownerRoleId !== null && (int) $invitedRoleId === (int) $ownerRoleId;
        $actorIsOwner = $ownerRoleId !== null && $actor->roles()
            ->where('roles.id', $ownerRoleId)
            ->where('roles.tenant_id', $tenantId)
            ->exists();

        if ($invitingOwner && ! $actorIsOwner) {
            abort(403, 'Solo un Owner puede invitar con el rol Owner.');
        }

        // Check if already a member of this tenant
        $existingUser = User::withoutGlobalScopes()->where('email', $validated['email'])->first();
        if ($existingUser !== null && TenantMembership::active()->where('tenant_id', $tenantId)->where('user_id', $existingUser->id)->exists()) {
            return response()->json(['message' => 'Este email ya es miembro del equipo.'], 422);
        }

        // Check for pending invitation
        $existingInvitation = Invitation::pending()
            ->where('email', $validated['email'])
            ->where('tenant_id', $tenantId)
            ->first();

        if ($existingInvitation) {
            return response()->json(['message' => 'Ya existe una invitación pendiente para este email.'], 422);
        }

        $invitation = Invitation::create([
            'tenant_id' => $tenantId,
            'email' => $validated['email'],
            'token' => Str::random(64),
            'role_name' => $validated['role_name'],
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        $invitation->load(['invitedBy:id,name,email', 'tenant:id,name']);

        WorkspaceAuditEvent::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actor->id,
            'event' => 'invitation.created',
            'metadata' => ['invitation_id' => $invitation->id, 'email' => $invitation->email, 'role' => $invitation->role_name],
        ]);

        Notification::route('mail', $validated['email'])
            ->notify(new InvitationNotification($invitation));

        return response()->json(['data' => $invitation], 201);
    }

    public function destroy(Request $request, Invitation $invitation): JsonResponse
    {
        $this->authorize('delete', $invitation);

        $invitation->delete();

        return response()->json(['message' => 'Invitación revocada.']);
    }

    /**
     * Public endpoint: view invitation details by token.
     */
    public function showByToken(string $token): JsonResponse
    {
        $invitation = Invitation::withoutGlobalScope(TenantScope::class)
            ->with('tenant:id,name', 'invitedBy:id,name')
            ->where('token', $token)
            ->first();

        if (! $invitation) {
            return response()->json(['message' => 'Invitación no encontrada.'], 404);
        }

        if ($invitation->isAccepted()) {
            return response()->json(['message' => 'Esta invitación ya fue aceptada.'], 410);
        }

        if ($invitation->isExpired()) {
            return response()->json(['message' => 'Esta invitación ha expirado.'], 410);
        }

        return response()->json([
            'data' => [
                'email' => $invitation->email,
                'role_name' => $invitation->role_name,
                'tenant_name' => $invitation->tenant->name ?? null,
                'inviter_name' => $invitation->invitedBy->name ?? null,
                'expires_at' => $invitation->expires_at,
            ],
        ]);
    }

    /**
     * Authenticated user accepts an invitation to join another tenant.
     */
    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
        ]);

        $invitation = Invitation::withoutGlobalScope(TenantScope::class)
            ->where('token', $validated['token'])
            ->first();

        if (! $invitation) {
            return response()->json(['message' => 'Invitación no encontrada.'], 404);
        }

        if ($invitation->isAccepted()) {
            return response()->json(['message' => 'Esta invitación ya fue aceptada.'], 410);
        }

        if ($invitation->isExpired()) {
            return response()->json(['message' => 'Esta invitación ha expirado.'], 410);
        }

        $user = $request->user();

        if ($user->email !== $invitation->email) {
            return response()->json([
                'message' => 'Esta invitación fue enviada a otro email.',
            ], 403);
        }

        $newTenantId = $invitation->tenant_id;
        $roleName = $invitation->role_name ?? 'Admin';

        $registrar = app(PermissionRegistrar::class);
        $roleExists = Role::query()
            ->where('tenant_id', $newTenantId)
            ->where('name', $roleName)
            ->exists();

        if (! $roleExists) {
            return response()->json(['message' => 'El rol asignado a la invitación ya no existe.'], 422);
        }

        DB::transaction(function () use ($user, $newTenantId, $registrar, $roleName, $invitation): void {
            $membership = TenantMembership::firstOrNew([
                'tenant_id' => $newTenantId,
                'user_id' => $user->id,
            ]);
            $membership->branch_id = null;
            $membership->joined_at = now();
            $membership->removed_at = null;
            $membership->save();

            $registrar->setPermissionsTeamId($newTenantId);
            $user->syncRoles([$roleName]);
            $invitation->update(['accepted_at' => now()]);
            WorkspaceAuditEvent::create([
                'tenant_id' => $newTenantId,
                'actor_user_id' => $user->id,
                'subject_user_id' => $user->id,
                'event' => 'membership.joined',
                'metadata' => ['invitation_id' => $invitation->id, 'role' => $roleName],
            ]);
        });

        $role = $user->roles()->where('roles.tenant_id', $newTenantId)->first();
        $tenant = Tenant::query()->whereKey($newTenantId)->first();

        return response()->json([
            'workspace' => ['id' => $newTenantId, 'name' => $tenant?->name],
            'user' => new \App\Http\Resources\UserResource($user->load(['tenant:id,name,owner_role_id,plan_id,trial_ends_at,navigation_labels', 'tenant.plan:id,key,name'])),
            'role' => RolePayload::transform($role, $tenant),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'message' => 'Te uniste al equipo exitosamente.',
        ]);
    }
}
