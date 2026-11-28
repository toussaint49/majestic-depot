<?php

namespace App\Policies;

use App\Models\Inventory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class InventoryPolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'inventaires';
    }

    /** Saisie des comptages : seulement en brouillon. */
    public function update(User $user, Model $record): bool
    {
        return $record instanceof Inventory && $record->isDraft() && $user->can('inventaires.create');
    }

    public function validate(User $user, Inventory $inventory): bool
    {
        return $inventory->isDraft() && $user->can('inventaires.validate');
    }
}
