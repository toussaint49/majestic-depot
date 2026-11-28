<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    use Auditable, BelongsToTenant;

    public const VALIDATED = 'validated';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::VALIDATED => 'Reçu', self::CANCELLED => 'Annulé'];

    protected $attributes = ['status' => self::VALIDATED];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'occurred_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function scopeWithBalance(Builder $query): Builder
    {
        return $query->withSum(['payments as paid_total' => fn ($q) => $q->where('status', SupplierPayment::VALID)], 'amount');
    }

    public function paidTotal(): int
    {
        if (array_key_exists('paid_total', $this->attributes)) {
            return (int) $this->attributes['paid_total'];
        }

        return (int) $this->payments()->where('status', SupplierPayment::VALID)->sum('amount');
    }

    /** Reste à payer sur cet achat. */
    public function balance(): int
    {
        return $this->isCancelled() ? 0 : max(0, $this->total - $this->paidTotal());
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }
}
