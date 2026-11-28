<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Apport ou retrait d'argent autorisé pendant une session de caisse. */
class CashMovement extends Model
{
    use Auditable, BelongsToTenant;

    public const DEPOSIT = 'deposit';
    public const WITHDRAWAL = 'withdrawal';

    public const TYPES = [self::DEPOSIT => 'Apport', self::WITHDRAWAL => 'Retrait'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'occurred_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
