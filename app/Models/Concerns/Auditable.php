<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;

/**
 * Journalise automatiquement création, modification et suppression.
 * Les attributs listés dans $auditExclude (et les mots de passe) sont ignorés.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => AuditLog::record('created', $model, [], $model->auditableAttributes($model->getAttributes())));

        static::updated(function ($model) {
            $changes = $model->auditableAttributes($model->getChanges());
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $old = array_intersect_key($model->getOriginal(), $changes);
            AuditLog::record('updated', $model, $old, $changes);
        });

        static::deleted(fn ($model) => AuditLog::record('deleted', $model, $model->auditableAttributes($model->getOriginal()), []));
    }

    protected function auditableAttributes(array $attributes): array
    {
        $exclude = array_merge(['password', 'remember_token'], $this->auditExclude ?? []);

        return array_diff_key($attributes, array_flip($exclude));
    }
}
