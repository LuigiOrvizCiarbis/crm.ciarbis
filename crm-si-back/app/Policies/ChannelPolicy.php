<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;

class ChannelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('channels.view_any')
            || $user->can('channels.view_assigned');
    }

    public function view(User $user, Channel $channel): bool
    {
        if ($user->can('channels.view_any')) {
            return true;
        }

        if (! $user->can('channels.view_assigned')) {
            return false;
        }

        return in_array((int) $channel->id, $user->accessibleChannelIds(), true);
    }

    public function create(User $user): bool
    {
        return $user->can('channels.create');
    }

    public function update(User $user, Channel $channel): bool
    {
        return $user->can('channels.update') && $this->canTouch($user, $channel);
    }

    public function delete(User $user, Channel $channel): bool
    {
        return $user->can('channels.delete') && $this->canTouch($user, $channel);
    }

    public function disconnect(User $user, Channel $channel): bool
    {
        return $user->can('channels.disconnect') && $this->canTouch($user, $channel);
    }

    public function manageUsers(User $user, Channel $channel): bool
    {
        return $user->can('channels.manage_users') && $this->canTouch($user, $channel);
    }

    public function connectWhatsapp(User $user): bool
    {
        return $user->can('channels.connect_whatsapp');
    }

    public function connectMail(User $user): bool
    {
        return $user->can('channels.connect_mail');
    }

    private function canTouch(User $user, Channel $channel): bool
    {
        return $user->can('channels.view_any')
            || in_array((int) $channel->id, $user->accessibleChannelIds(), true);
    }
}
