<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Jetons d'accès API : réservés à qui peut modifier les paramètres de l'entreprise. */
class ApiTokenPolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'parametres';
    }

    public function viewAny(User $user): bool
    {
        return $user->can('parametres.update');
    }

    public function create(User $user): bool
    {
        return $user->can('parametres.update');
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }
}
