<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tags.view');
    }

    public function view(User $user, Tag $tag): bool
    {
        return $user->can('tags.view');
    }

    public function create(User $user): bool
    {
        // Los vendedores crean etiquetas al vuelo al etiquetar; editarlas o
        // borrarlas sigue siendo de gestión.
        return $user->can('tags.attach');
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->can('tags.manage');
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->can('tags.manage');
    }

    public function attach(User $user): bool
    {
        return $user->can('tags.attach');
    }
}
