<?php

namespace App\Services\Notifications;

use App\Models\Conversation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resuelve qué usuarios de un workspace deben recibir una notificación.
 *
 * Corre fuera del request (jobs, webhooks), así que replica en memoria lo que
 * hace ResolveWorkspaceContext: tenant/branch de la membresía y team de Spatie,
 * para que las policies evalúen contra el workspace correcto.
 */
class NotificationRecipients
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * Asignados de la conversación (assigned_to + pivot) que todavía pueden
     * verla; si no queda ninguno (sin asignar, o el asignado dejó el workspace
     * o perdió acceso), todos los miembros que pueden verla según
     * ConversationPolicy::view.
     *
     * @return Collection<int, User>
     */
    public function forConversation(Conversation $conversation): Collection
    {
        $assignedIds = $conversation->users()->pluck('users.id')
            ->push($conversation->assigned_to)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $viewers = fn (Collection $members) => $this->withinTeam($conversation->tenant_id, fn () => $members
            ->filter(fn (User $user) => Gate::forUser($user)->allows('view', $conversation))
            ->values());

        $assignees = $assignedIds === [] ? collect() : $viewers($this->members($conversation->tenant_id, $assignedIds));

        return $assignees->isNotEmpty() ? $assignees : $viewers($this->members($conversation->tenant_id));
    }

    /**
     * Miembros activos indicados, sin chequeo de permisos (ej. destinatario
     * explícito de una asignación o de un handoff).
     *
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    public function users(int $tenantId, array $userIds): Collection
    {
        return $userIds === [] ? collect() : $this->members($tenantId, $userIds);
    }

    /** @return Collection<int, User> */
    public function owners(int $tenantId): Collection
    {
        $members = $this->members($tenantId);

        return $this->withinTeam($tenantId, fn () => $members
            ->filter(fn (User $user) => $user->isTenantOwner())
            ->values());
    }

    /**
     * @param  list<int>|null  $userIds
     * @return Collection<int, User>
     */
    private function members(int $tenantId, ?array $userIds = null): Collection
    {
        $memberships = TenantMembership::query()
            ->active()
            ->with(['user', 'tenant'])
            ->where('tenant_id', $tenantId)
            ->when($userIds !== null, fn ($query) => $query->whereIn('user_id', $userIds))
            ->get();

        return $memberships
            ->filter(fn (TenantMembership $membership) => $membership->user !== null)
            ->map(function (TenantMembership $membership) {
                $user = $membership->user;
                $user->setAttribute('tenant_id', $membership->tenant_id);
                $user->setAttribute('branch_id', $membership->branch_id);
                $user->setRelation('tenant', $membership->tenant);
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $user->forgetTenantOwnerCache();

                return $user;
            })
            ->values();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withinTeam(int $tenantId, callable $callback): mixed
    {
        $previous = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($tenantId);

        try {
            return $callback();
        } finally {
            $this->registrar->setPermissionsTeamId($previous);
        }
    }
}
