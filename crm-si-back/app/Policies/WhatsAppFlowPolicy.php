<?php

namespace App\Policies;

use App\Models\User;

class WhatsAppFlowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('flows.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('flows.manage');
    }

    public function send(User $user): bool
    {
        return $user->can('flows.send');
    }
}
