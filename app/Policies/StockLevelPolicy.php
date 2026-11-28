<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Les soldes ne se modifient jamais directement : uniquement via des mouvements. */
class StockLevelPolicy extends PermissionPolicy
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
