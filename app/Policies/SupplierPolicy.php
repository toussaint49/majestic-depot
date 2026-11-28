<?php

namespace App\Policies;

class SupplierPolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'fournisseurs';
    }
}
