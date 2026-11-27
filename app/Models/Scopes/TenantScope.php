<?php

namespace App\Models\Scopes;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limite toute requête au tenant actif. Sans tenant actif, aucune ligne n'est
 * renvoyée (sécurité par défaut) : les traitements globaux doivent passer par
 * CurrentTenant::run() ou désactiver explicitement le scope.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(CurrentTenant::class)->id();
        $column = $model->qualifyColumn('tenant_id');

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $tenantId);
    }
}
