<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inventory extends Model
{
    use Auditable, BelongsToTenant;

    public const DRAFT = 'draft';
    public const VALIDATED = 'validated';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Brouillon',
        self::VALIDATED => 'Validé',
        self::CANCELLED => 'Annulé',
    ];

    protected $attributes = ['status' => self::DRAFT];

    protected $fillable = ['depot_id', 'number', 'counted_at', 'notes'];

    protected function casts(): array
    {
        return ['counted_at' => 'datetime', 'validated_at' => 'datetime'];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
