<?php

namespace App\Services;

use App\Exceptions\SaleException;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\PaymentAllocation;
use App\Models\Sale;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Encaissements : à la vente ou en règlement de crédit.
 * Chaque paiement crédite un compte (espèces, MoMo, OM…) et est affecté à des ventes.
 */
class PaymentService
{
    public function __construct(private SequenceService $sequences) {}

    /** Paiement reçu au moment de la vente. */
    public function recordSalePayment(Sale $sale, PaymentAccount $account, int $amount, ?string $reference = null, DateTimeInterface|string|null $occurredAt = null): Payment
    {
        return DB::transaction(function () use ($sale, $account, $amount, $reference, $occurredAt) {
            $this->assertUsable($account, $amount);
            if ($amount > $sale->balance()) {
                throw new SaleException('Le paiement dépasse le reste dû de la vente.');
            }

            $payment = $this->create($account, $amount, $sale->customer_id, $sale->id, $reference, $occurredAt ?? $sale->occurred_at);
            PaymentAllocation::create(['payment_id' => $payment->id, 'sale_id' => $sale->id, 'amount' => $amount]);
            $this->post($account, $amount, 'sale_payment', $payment, "Vente {$sale->number}", $payment->occurred_at);

            return $payment;
        });
    }

    /**
     * Règlement d'un client : affecté à la vente indiquée, sinon aux ventes les plus anciennes.
     * Pas d'avance en V1 : le montant ne peut pas dépasser ce que doit le client.
     */
    public function recordCustomerPayment(Customer $customer, PaymentAccount $account, int $amount, ?Sale $sale = null, ?string $reference = null, DateTimeInterface|string|null $occurredAt = null): Payment
    {
        return DB::transaction(function () use ($customer, $account, $amount, $sale, $reference, $occurredAt) {
            $this->assertUsable($account, $amount);
            Customer::whereKey($customer->id)->lockForUpdate()->first();

            $open = $customer->openSales();
            if ($sale) {
                if ($sale->customer_id !== $customer->id) {
                    throw new SaleException('Cette vente n\'appartient pas à ce client.');
                }
                $open = $open->sortBy(fn (Sale $s) => $s->id === $sale->id ? 0 : 1)->values();
            }

            $due = (int) $open->sum(fn (Sale $s) => $s->balance());
            if ($amount > $due) {
                throw new SaleException(sprintf('Le client ne doit que %s FCFA.', number_format($due, 0, ',', ' ')));
            }

            $payment = $this->create($account, $amount, $customer->id, null, $reference, $occurredAt);
            $left = $amount;
            foreach ($open as $openSale) {
                if ($left === 0) {
                    break;
                }
                $part = min($left, $openSale->balance());
                PaymentAllocation::create(['payment_id' => $payment->id, 'sale_id' => $openSale->id, 'amount' => $part]);
                $left -= $part;
            }

            $this->post($account, $amount, 'customer_payment', $payment, "Règlement {$customer->name}", $payment->occurred_at);

            return $payment;
        });
    }

    /** Annulation : le paiement reste dans l'historique, une écriture inverse débite le compte. */
    public function cancel(Payment $payment, string $reason): Payment
    {
        if (trim($reason) === '') {
            throw new SaleException('Le motif d\'annulation est obligatoire.');
        }

        return DB::transaction(function () use ($payment, $reason) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $payment->isValid()) {
                throw new SaleException('Ce paiement est déjà annulé.');
            }

            $payment->forceFill([
                'status' => Payment::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            $this->post($payment->account, -$payment->amount, 'payment_cancel', $payment, "Annulation {$payment->number} : $reason", now());

            return $payment;
        });
    }

    private function create(PaymentAccount $account, int $amount, ?int $customerId, ?int $saleId, ?string $reference, DateTimeInterface|string|null $occurredAt): Payment
    {
        return Payment::create([
            'number' => $this->sequences->next('payment'),
            'customer_id' => $customerId,
            'sale_id' => $saleId,
            'payment_account_id' => $account->id,
            'amount' => $amount,
            'reference' => $reference,
            'user_id' => auth()->id(),
            'occurred_at' => $occurredAt ? Carbon::parse($occurredAt) : now(),
        ]);
    }

    private function post(PaymentAccount $account, int $amount, string $type, Payment $payment, string $label, DateTimeInterface $occurredAt): void
    {
        AccountTransaction::create([
            'payment_account_id' => $account->id,
            'type' => $type,
            'amount' => $amount,
            'source_type' => $payment->getMorphClass(),
            'source_id' => $payment->id,
            'label' => $label,
            'user_id' => auth()->id(),
            'occurred_at' => $occurredAt,
        ]);
    }

    private function assertUsable(PaymentAccount $account, int $amount): void
    {
        if ($amount <= 0) {
            throw new SaleException('Le montant d\'un paiement doit être positif.');
        }
        if (! $account->is_active) {
            throw new SaleException("Le compte « {$account->name} » est désactivé.");
        }
    }
}
