<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Lecture seule : les quantités changent uniquement via PackagingService. */
class PackagingLevelPolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'emballages';
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
