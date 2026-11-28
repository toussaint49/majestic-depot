<?php

namespace App\Policies;

class ProductPolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'produits';
    }
}
