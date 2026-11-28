<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/** Écriture sur un compte de paiement. Immuable : on corrige par une écriture inverse. */
class AccountTransaction extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const TYPES = [
        'sale_payment' => 'Encaissement vente',
        'customer_payment' => 'Règlement client',
        'payment_cancel' => 'Annulation encaissement',
        'cash_deposit' => 'Apport',
        'cash_withdrawal' => 'Retrait',
        'cash_difference' => 'Écart de caisse',
    ];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une écriture de compte ne peut pas être modifiée.'));
        static::deleting(fn () => throw new LogicException('Une écriture de compte ne peut pas être supprimée.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
