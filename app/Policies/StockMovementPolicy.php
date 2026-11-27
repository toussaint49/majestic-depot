<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Les mouvements sont créés par StockService et ne sont jamais modifiés. */
class StockMovementPolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'stocks';
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }
}
