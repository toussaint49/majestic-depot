<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Comptes de paiement : consultables avec la trésorerie, configurés dans les paramètres. */
class PaymentAccountPolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'tresorerie';
    }

    public function create(User $user): bool
    {
        return $user->can('parametres.update');
    }

    public function update(User $user, Model $record): bool
    {
        return $user->can('parametres.update');
    }
}
