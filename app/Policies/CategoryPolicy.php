<?php

namespace App\Policies;

class CategoryPolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'produits';
    }
}
