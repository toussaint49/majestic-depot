<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Une vente validée ne se modifie pas : elle s'annule (contrepassation). */
class SalePolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'ventes';
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function cancel(User $user, Sale $sale): bool
    {
        return ! $sale->isCancelled() && $user->can('ventes.cancel');
    }
}
