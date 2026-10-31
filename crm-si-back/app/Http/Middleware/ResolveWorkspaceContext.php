<?php

namespace App\Http\Middleware;

use App\Models\TenantMembership;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class ResolveWorkspaceContext
{
    public function __construct(
        private CurrentWorkspace $workspace,
        private PermissionRegistrar $registrar,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $requestedId = $request->header('X-Workspace-Id');
        // Compatibility phase: clients that have not shipped the header retain
        // their legacy context. New clients always use a verified membership.
        $tenantId = $requestedId !== null ? filter_var($requestedId, FILTER_VALIDATE_INT) : $user->tenant_id;
        if (! $tenantId) {
            return response()->json(['message' => 'Seleccioná un workspace para continuar.'], 400);
        }

        $membership = TenantMembership::query()
            ->active()
            ->with('tenant')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'No pertenecés a este workspace.'], 403);
        }

        if ($membership->tenant->status !== 'active'
            && ! $request->is('api/workspaces/*/restore')) {
            return response()->json(['message' => 'Este workspace está desactivado.'], 423);
        }

        $this->workspace->set($membership);
        // Existing code reads these attributes. They are only changed in-memory
        // for this request; the persisted legacy columns are untouched.
        $user->setAttribute('tenant_id', $membership->tenant_id);
        $user->setAttribute('branch_id', $membership->branch_id);
        $user->unsetRelation('tenant')->setRelation('tenant', $membership->tenant);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $user->forgetTenantOwnerCache();
        $this->registrar->setPermissionsTeamId($membership->tenant_id);

        return $next($request);
    }
}
