<?php

namespace App\Policies;

/** Les types d'emballages font partie du paramétrage du catalogue. */
class PackagingTypePolicy extends PermissionPolicy
{
    protected function module(): string
    {
        return 'produits';
    }
}
