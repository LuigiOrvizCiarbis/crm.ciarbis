<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Policies\Concerns\ChecksBranchAccess;

class WhatsAppGroupPolicy
{
    use ChecksBranchAccess;

    public function viewAny(User $user): bool
    {
        return $user->can('whatsapp_groups.view');
    }

    public function view(User $user, WhatsAppGroup $group): bool
    {
        return $user->can('whatsapp_groups.view') && $this->hasChannelAccess($user, $group);
    }

    /**
     * $channel es opcional para no romper `$user->can('create', WhatsAppGroup::class)`
     * (Gate::authorize sin instancia), pero el controller de store() SIEMPRE
     * la pasa: un Member sin permisos de gestión de canales (ChannelPolicy no
     * le da 'view' porque memberPermissions() no incluye channels.*) igual
     * puede crear un grupo en un canal al que tiene acceso.
     */
    public function create(User $user, ?Channel $channel = null): bool
    {
        if (! $user->can('whatsapp_groups.create')) {
            return false;
        }

        if ($channel === null) {
            return true;
        }

        if (! $this->passesBranchCheck($user, $channel)) {
            return false;
        }

        return $user->can('channels.view_any')
            || in_array((int) $channel->id, $user->accessibleChannelIds(), true);
    }

    public function update(User $user, WhatsAppGroup $group): bool
    {
        return $user->can('whatsapp_groups.update') && $this->hasChannelAccess($user, $group);
    }

    public function delete(User $user, WhatsAppGroup $group): bool
    {
        return $user->can('whatsapp_groups.delete') && $this->hasChannelAccess($user, $group);
    }

    public function invite(User $user, WhatsAppGroup $group): bool
    {
        return $user->can('whatsapp_groups.invite') && $this->hasChannelAccess($user, $group);
    }

    public function manageParticipants(User $user, WhatsAppGroup $group): bool
    {
        return $user->can('whatsapp_groups.manage_participants') && $this->hasChannelAccess($user, $group);
    }

    private function hasChannelAccess(User $user, WhatsAppGroup $group): bool
    {
        if (! $this->passesBranchCheck($user, $group)) {
            return false;
        }

        if ($user->can('channels.view_any')) {
            return true;
        }

        return in_array((int) $group->channel_id, $user->accessibleChannelIds(), true);
    }
}
