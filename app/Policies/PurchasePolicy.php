<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Un achat enregistré ne se modifie pas : il s'annule (contrepassation). */
class PurchasePolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'achats';
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function pay(User $user, Purchase $purchase): bool
    {
        return ! $purchase->isCancelled() && $purchase->balance() > 0 && $user->can('achats.validate');
    }

    public function cancel(User $user, Purchase $purchase): bool
    {
        return ! $purchase->isCancelled() && $user->can('achats.cancel');
    }
}
