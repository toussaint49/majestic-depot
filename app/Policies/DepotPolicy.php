<?php

namespace App\Policies;

use App\Models\Depot;
use App\Models\User;

class DepotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('depots.view');
    }

    public function view(User $user, Depot $depot): bool
    {
        return $user->can('depots.view');
    }

    public function create(User $user): bool
    {
        return $user->can('depots.create');
    }

    public function update(User $user, Depot $depot): bool
    {
        return $user->can('depots.update');
    }

    /** Un dépôt n'est jamais supprimé : on le désactive. */
    public function delete(User $user, Depot $depot): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
