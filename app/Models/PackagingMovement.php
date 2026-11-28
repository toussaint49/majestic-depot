<?php

namespace App\Models;

use App\Enums\PackagingMovementType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/** Mouvement d'emballages : immuable. */
class PackagingMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'type' => PackagingMovementType::class,
            'quantity' => 'integer',
            'quantity_after' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException("Un mouvement d'emballages ne peut pas être modifié."));
        static::deleting(fn () => throw new LogicException("Un mouvement d'emballages ne peut pas être supprimé."));
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function packagingType(): BelongsTo
    {
        return $this->belongsTo(PackagingType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
