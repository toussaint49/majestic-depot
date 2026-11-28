<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PaymentPolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'paiements';
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function cancel(User $user, Payment $payment): bool
    {
        return $payment->isValid() && $user->can('paiements.cancel');
    }
}
