<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use Auditable, BelongsToTenant;

    public const VALIDATED = 'validated';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::VALIDATED => 'Validée', self::CANCELLED => 'Annulée'];

    protected $attributes = ['status' => self::VALIDATED];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'paid_at_sale' => 'integer',
            'credit_amount' => 'integer',
            'due_date' => 'date',
            'occurred_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** Ajoute `paid_total` : somme des affectations de paiements valides. */
    public function scopeWithBalance(Builder $query): Builder
    {
        return $query->withSum(['allocations as paid_total' => fn ($q) => $q->whereHas('payment', fn ($p) => $p->where('status', Payment::VALID))], 'amount');
    }

    public function paidTotal(): int
    {
        if (array_key_exists('paid_total', $this->attributes)) {
            return (int) $this->attributes['paid_total'];
        }

        return (int) $this->allocations()->whereHas('payment', fn ($p) => $p->where('status', Payment::VALID))->sum('amount');
    }

    /** Reste dû. */
    public function balance(): int
    {
        return $this->status === self::CANCELLED ? 0 : max(0, $this->total - $this->paidTotal());
    }

    public function isOverdue(): bool
    {
        return $this->balance() > 0 && $this->due_date !== null && $this->due_date->lt(today());
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }
}
