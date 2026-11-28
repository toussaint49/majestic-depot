<?php

namespace App\Policies;

use App\Models\CashSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CashSessionPolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'caisses';
    }

    public function create(User $user): bool
    {
        return $user->can('caisses.open');
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function close(User $user, CashSession $session): bool
    {
        return $session->isOpen() && $user->can('caisses.close');
    }

    public function validate(User $user, CashSession $session): bool
    {
        return $session->status === CashSession::CLOSED && $user->can('caisses.validate');
    }

    /** Apports et retraits : réservés aux responsables. */
    public function move(User $user, CashSession $session): bool
    {
        return $session->isOpen() && $user->can('caisses.validate');
    }
}
