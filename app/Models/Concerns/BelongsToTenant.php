<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * À utiliser sur toute table métier rattachée à une entreprise.
 *
 * - filtre automatiquement les lectures sur le tenant actif ;
 * - impose le tenant actif à la création (le tenant_id fourni par le client est ignoré) ;
 * - interdit de déplacer une ligne vers une autre entreprise.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $tenantId = app(CurrentTenant::class)->id();

            if ($tenantId === null) {
                throw new LogicException('Aucune entreprise active : impossible de créer '.class_basename($model).'.');
            }

            $model->tenant_id = $tenantId;
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('Le rattachement à une entreprise ne peut pas être modifié.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
