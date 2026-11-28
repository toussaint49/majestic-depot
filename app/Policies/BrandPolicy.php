<?php

namespace App\Policies;

class BrandPolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'produits';
    }
}
