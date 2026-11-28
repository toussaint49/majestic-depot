<?php

namespace App\Services;

use App\Enums\PackagingMovementType;
use App\Enums\StockMovementType;
use App\Exceptions\PurchaseException;
use App\Models\Depot;
use App\Models\PackagingMovement;
use App\Models\PackagingType;
use App\Models\PaymentAccount;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Achats fournisseurs (ravitaillement).
 *
 * Un achat enregistré, dans une seule transaction :
 * - fait entrer la marchandise en stock au prix de la facture (le CUMP se recalcule) ;
 * - fait sortir les vides remis au fournisseur ;
 * - enregistre le paiement éventuel (sortie d'argent de la caisse ouverte) ;
 * - le reste devient une dette fournisseur.
 */
class PurchaseService
{
    public function __construct(
        private StockService $stock,
        private PackagingService $packaging,
        private SequenceService $sequences,
        private AccountLedger $ledger,
        private CashSessionService $cash,
    ) {}

    /**
     * @param array{
     *   depot_id: int,
     *   supplier_id: int,
     *   invoice_number?: string|null,
     *   occurred_at?: string|DateTimeInterface|null,
     *   notes?: string|null,
     *   lines: list<array{product_unit_id: int, quantity: int, unit_price: int}>,
     *   empties_returned?: list<array{packaging_type_id: int, quantity: int}>,
     *   payments?: list<array{payment_account_id: int, amount: int, reference?: string|null}>,
     * } $data
     */
    public function create(array $data): Purchase
    {
        $lines = collect($data['lines'] ?? [])->filter(fn ($l) => ! empty($l['product_unit_id']) && (int) ($l['quantity'] ?? 0) > 0)->values();
        if ($lines->isEmpty()) {
            throw new PurchaseException('Ajoutez au moins un produit à l\'achat.');
        }
        if ($lines->contains(fn ($l) => (int) ($l['unit_price'] ?? -1) < 0 || ! isset($l['unit_price']) || $l['unit_price'] === '')) {
            throw new PurchaseException('Indiquez le prix d\'achat de chaque ligne.');
        }

        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();
        if ($occurredAt->gt(now()->addMinutes(5))) {
            throw new PurchaseException('La date de l\'achat ne peut pas être dans le futur.');
        }

        return DB::transaction(function () use ($data, $lines, $occurredAt) {
            $depot = Depot::findOrFail($data['depot_id']);
            $supplier = Supplier::findOrFail($data['supplier_id']);
            if (! $supplier->is_active) {
                throw new PurchaseException("Le fournisseur « {$supplier->name} » est désactivé.");
            }

            $invoice = trim((string) ($data['invoice_number'] ?? '')) ?: null;
            if ($invoice && Purchase::where('supplier_id', $supplier->id)->where('invoice_number', $invoice)->where('status', Purchase::VALIDATED)->exists()) {
                throw new PurchaseException("La facture n° $invoice de « {$supplier->name} » est déjà enregistrée.");
            }

            $prepared = $lines->map(function ($l) {
                $unit = ProductUnit::with('product')->findOrFail($l['product_unit_id']);
                $qty = (int) $l['quantity'];
                $price = (int) $l['unit_price'];

                return [
                    'unit' => $unit,
                    'product_id' => $unit->product_id,
                    'product_unit_id' => $unit->id,
                    'product_name' => $unit->product->name,
                    'unit_name' => $unit->name,
                    'factor' => $unit->factor,
                    'quantity' => $qty,
                    'base_quantity' => $qty * $unit->factor,
                    'unit_price' => $price,
                    'line_total' => $qty * $price,
                    'base_unit_cost' => round($price / $unit->factor, 4),
                ];
            });

            $purchase = Purchase::create([
                'depot_id' => $depot->id,
                'supplier_id' => $supplier->id,
                'number' => $this->sequences->next('purchase'),
                'invoice_number' => $invoice,
                'total' => (int) $prepared->sum('line_total'),
                'notes' => $data['notes'] ?? null,
                'user_id' => auth()->id(),
                'occurred_at' => $occurredAt,
            ]);

            foreach ($prepared as $line) {
                $this->stock->receive($depot, $line['unit']->product, $line['base_quantity'], (float) $line['base_unit_cost'], StockMovementType::PurchaseIn, $purchase, "Achat {$purchase->number}", $occurredAt);
                unset($line['unit']);
                $purchase->lines()->create($line);
            }

            foreach (collect($data['empties_returned'] ?? [])->filter(fn ($e) => ! empty($e['packaging_type_id']) && (int) ($e['quantity'] ?? 0) > 0) as $e) {
                $this->packaging->issue($depot, PackagingType::findOrFail($e['packaging_type_id']), (int) $e['quantity'], PackagingMovementType::SupplierOut, "Remis à {$supplier->name} ({$purchase->number})", $occurredAt, $purchase);
            }

            foreach (collect($data['payments'] ?? [])->filter(fn ($p) => (int) ($p['amount'] ?? 0) > 0) as $p) {
                $this->pay($purchase, PaymentAccount::findOrFail($p['payment_account_id']), (int) $p['amount'], $p['reference'] ?? null, $occurredAt);
            }

            return $purchase->refresh();
        });
    }

    /** Règlement d'un achat : l'argent sort du compte choisi. */
    public function pay(Purchase $purchase, PaymentAccount $account, int $amount, ?string $reference = null, DateTimeInterface|string|null $occurredAt = null): SupplierPayment
    {
        if ($amount <= 0) {
            throw new PurchaseException('Le montant d\'un règlement doit être positif.');
        }
        if (! $account->is_active) {
            throw new PurchaseException("Le compte « {$account->name} » est désactivé.");
        }

        return DB::transaction(function () use ($purchase, $account, $amount, $reference, $occurredAt) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($purchase->isCancelled()) {
                throw new PurchaseException('Cet achat est annulé.');
            }
            if ($amount > $purchase->balance()) {
                throw new PurchaseException(sprintf('Il ne reste que %s FCFA à payer sur cet achat.', number_format($purchase->balance(), 0, ',', ' ')));
            }

            $session = $this->cash->forPayment($purchase->depot);
            $available = $session ? ($this->cash->expected($session)[$account->id] ?? 0) : $this->ledger->balance($account);
            if ($amount > $available) {
                throw new PurchaseException(sprintf('Fonds insuffisants : seulement %s FCFA disponibles sur « %s ».', number_format($available, 0, ',', ' '), $account->name));
            }

            $payment = SupplierPayment::create([
                'number' => $this->sequences->next('supplier_payment'),
                'supplier_id' => $purchase->supplier_id,
                'purchase_id' => $purchase->id,
                'depot_id' => $purchase->depot_id,
                'payment_account_id' => $account->id,
                'amount' => $amount,
                'reference' => $reference,
                'user_id' => auth()->id(),
                'occurred_at' => $occurredAt ? Carbon::parse($occurredAt) : now(),
            ]);

            $this->ledger->post($account, -$amount, 'supplier_payment', $payment, "Achat {$purchase->number} — {$purchase->supplier->name}", $payment->occurred_at, $session);

            return $payment;
        });
    }

    /** Annule un règlement fournisseur : l'argent revient sur le compte. */
    public function cancelPayment(SupplierPayment $payment, string $reason): SupplierPayment
    {
        if (trim($reason) === '') {
            throw new PurchaseException('Le motif d\'annulation est obligatoire.');
        }

        return DB::transaction(function () use ($payment, $reason) {
            $payment = SupplierPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $payment->isValid()) {
                throw new PurchaseException('Ce règlement est déjà annulé.');
            }
            $payment->forceFill(['status' => SupplierPayment::CANCELLED, 'cancelled_by' => auth()->id(), 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();

            $session = $this->cash->forPayment($payment->depot);
            $this->ledger->post($payment->account, $payment->amount, 'supplier_payment_cancel', $payment, "Annulation {$payment->number} : $reason", now(), $session);

            return $payment;
        });
    }

    /**
     * Annulation d'un achat (erreur de saisie, marchandise renvoyée) : la marchandise
     * ressort du stock et les vides remis reviennent. Refusé s'il reste des règlements
     * ou si la marchandise a déjà été vendue.
     */
    public function cancel(Purchase $purchase, string $reason): Purchase
    {
        if (trim($reason) === '') {
            throw new PurchaseException('Le motif d\'annulation est obligatoire.');
        }

        return DB::transaction(function () use ($purchase, $reason) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($purchase->isCancelled()) {
                throw new PurchaseException('Cet achat est déjà annulé.');
            }
            if ($purchase->payments()->where('status', SupplierPayment::VALID)->exists()) {
                throw new PurchaseException('Cet achat a des règlements. Annulez-les d\'abord.');
            }

            foreach ($purchase->lines()->with('product')->get() as $line) {
                $this->stock->issue($purchase->depot, $line->product, $line->base_quantity, StockMovementType::SupplierReturn, $purchase, "Annulation {$purchase->number} : $reason");
            }

            $given = PackagingMovement::where('source_type', $purchase->getMorphClass())->where('source_id', $purchase->id)
                ->where('type', PackagingMovementType::SupplierOut)->get();
            foreach ($given as $m) {
                $this->packaging->receive($purchase->depot, $m->packagingType, -$m->quantity, PackagingMovementType::SupplierIn, "Annulation {$purchase->number}", null, $purchase);
            }

            $purchase->forceFill(['status' => Purchase::CANCELLED, 'cancelled_by' => auth()->id(), 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();

            return $purchase;
        });
    }
}
