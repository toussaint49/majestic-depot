<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\CashSession;
use App\Models\PaymentAccount;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/** Seul point d'écriture du grand livre des comptes de paiement. */
class AccountLedger
{
    public function post(PaymentAccount $account, int $amount, string $type, ?Model $source, string $label, DateTimeInterface $occurredAt, ?CashSession $session = null): AccountTransaction
    {
        return AccountTransaction::create([
            'payment_account_id' => $account->id,
            'cash_session_id' => $session?->id,
            'type' => $type,
            'amount' => $amount,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'label' => $label,
            'user_id' => auth()->id(),
            'occurred_at' => $occurredAt,
        ]);
    }

    public function balance(PaymentAccount $account): int
    {
        return (int) AccountTransaction::where('payment_account_id', $account->id)->sum('amount');
    }
}
