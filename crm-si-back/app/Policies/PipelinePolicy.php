<?php

namespace App\Policies;

use App\Models\Pipeline;
use App\Models\User;

class PipelinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('pipeline_stages.view') || $user->can('pipeline_stages.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('pipeline_stages.manage');
    }

    public function update(User $user, Pipeline $pipeline): bool
    {
        return $user->can('pipeline_stages.manage');
    }

    public function delete(User $user, Pipeline $pipeline): bool
    {
        return $user->can('pipeline_stages.manage');
    }
}
