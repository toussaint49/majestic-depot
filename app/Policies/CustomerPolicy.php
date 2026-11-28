<?php

namespace App\Policies;

class CustomerPolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'clients';
    }
}
