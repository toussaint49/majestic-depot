<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Argent réellement reçu, sur un compte de paiement, affecté à une ou plusieurs ventes. */
class Payment extends Model
{
    use Auditable, BelongsToTenant;

    public const VALID = 'valid';
    public const CANCELLED = 'cancelled';

    protected $attributes = ['status' => self::VALID];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'occurred_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function isValid(): bool
    {
        return $this->status === self::VALID;
    }
}
