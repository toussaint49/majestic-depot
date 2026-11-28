<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\SaleException;
use App\Models\Customer;
use App\Models\Depot;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\ProductUnit;
use App\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrement et annulation des ventes.
 *
 * Une vente met à jour, dans une seule transaction : stock de boissons, stock de vides,
 * encaissements par compte et reste dû du client. Si une étape échoue, rien n'est enregistré.
 */
class SaleService
{
    public function __construct(
        private StockService $stock,
        private PackagingService $packaging,
        private PaymentService $payments,
        private SequenceService $sequences,
    ) {}

    /**
     * @param array{
     *   depot_id: int,
     *   customer_id?: int|null,
     *   occurred_at?: string|\DateTimeInterface|null,
     *   notes?: string|null,
     *   empties_returned?: bool,
     *   lines: list<array{product_unit_id: int, quantity: int}>,
     *   payments?: list<array{payment_account_id: int, amount: int, reference?: string|null}>,
     * } $data
     */
    public function create(array $data): Sale
    {
        $lines = collect($data['lines'] ?? [])->filter(fn ($l) => ! empty($l['product_unit_id']) && (int) ($l['quantity'] ?? 0) > 0)->values();
        if ($lines->isEmpty()) {
            throw new SaleException('Ajoutez au moins un produit à la vente.');
        }

        $payments = collect($data['payments'] ?? [])->filter(fn ($p) => (int) ($p['amount'] ?? 0) > 0)->values();
        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();
        if ($occurredAt->gt(now()->addMinutes(5))) {
            throw new SaleException('La date de la vente ne peut pas être dans le futur.');
        }

        return DB::transaction(function () use ($data, $lines, $payments, $occurredAt) {
            $depot = Depot::findOrFail($data['depot_id']);
            $customer = ! empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;

            // 1. Lignes et total, avec instantané du prix et du conditionnement.
            $prepared = $lines->map(function ($l) {
                $unit = ProductUnit::with('product')->findOrFail($l['product_unit_id']);
                if (! $unit->is_active || ! $unit->product->is_active) {
                    throw new SaleException("« {$unit->product->name} ({$unit->name}) » n'est plus en vente.");
                }
                $qty = (int) $l['quantity'];

                return [
                    'unit' => $unit,
                    'product_id' => $unit->product_id,
                    'product_unit_id' => $unit->id,
                    'product_name' => $unit->product->name,
                    'unit_name' => $unit->name,
                    'factor' => $unit->factor,
                    'unit_price' => $unit->price,
                    'quantity' => $qty,
                    'base_quantity' => $qty * $unit->factor,
                    'line_total' => $qty * $unit->price,
                ];
            });

            $total = (int) $prepared->sum('line_total');
            $paid = (int) $payments->sum(fn ($p) => (int) $p['amount']);

            if ($paid > $total) {
                throw new SaleException(sprintf(
                    'Le montant encaissé (%s FCFA) dépasse le total de la vente (%s FCFA). Saisissez le montant gardé, pas le billet reçu.',
                    number_format($paid, 0, ',', ' '), number_format($total, 0, ',', ' '),
                ));
            }
            $credit = $total - $paid;
            if ($credit > 0 && ! $customer) {
                throw new SaleException('Une vente à crédit doit être rattachée à un client.');
            }

            // 2. Vente.
            $sale = Sale::create([
                'depot_id' => $depot->id,
                'customer_id' => $customer?->id,
                'number' => $this->sequences->next('sale'),
                'total' => $total,
                'paid_at_sale' => $paid,
                'credit_amount' => $credit,
                'due_date' => $credit > 0 ? $occurredAt->copy()->startOfDay()->addDays($customer->creditDays()) : null,
                'notes' => $data['notes'] ?? null,
                'user_id' => auth()->id(),
                'occurred_at' => $occurredAt,
            ]);

            // 3. Sorties de stock (bloque si stock insuffisant).
            foreach ($prepared as $line) {
                $movement = $this->stock->issue($depot, $line['unit']->product, $line['base_quantity'], StockMovementType::SaleOut, $sale, occurredAt: $occurredAt);
                unset($line['unit']);
                $sale->lines()->create($line + ['unit_cost' => $movement->unit_cost]);
            }

            // 4. Vides : le client doit rapporter ses emballages, sinon la vente est refusée.
            $items = $prepared->map(fn ($l) => [$l['product_unit_id'], $l['quantity']])->all();
            $required = $this->packaging->required($items);
            if ($required !== []) {
                $this->packaging->collectForSale($depot, $items, $sale, ! empty($data['empties_returned']) ? $required : [], $occurredAt);
            }

            // 5. Encaissements (paiements mixtes).
            foreach ($payments as $p) {
                $this->payments->recordSalePayment($sale, PaymentAccount::findOrFail($p['payment_account_id']), (int) $p['amount'], $p['reference'] ?? null, $occurredAt);
            }

            return $sale->refresh();
        });
    }

    /**
     * Annulation complète par contrepassation : stock et vides réintégrés, encaissements
     * de la vente annulés (remboursés). La vente reste visible avec le statut « Annulée ».
     */
    public function cancel(Sale $sale, string $reason): Sale
    {
        if (trim($reason) === '') {
            throw new SaleException('Le motif d\'annulation est obligatoire.');
        }

        return DB::transaction(function () use ($sale, $reason) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->isCancelled()) {
                throw new SaleException('Cette vente est déjà annulée.');
            }

            $settlements = $sale->allocations()->with('payment')->get()
                ->filter(fn ($a) => $a->payment->isValid() && $a->payment->sale_id !== $sale->id);
            if ($settlements->isNotEmpty()) {
                throw new SaleException('Cette vente a reçu des règlements ultérieurs ('.$settlements->pluck('payment.number')->unique()->implode(', ').'). Annulez-les d\'abord.');
            }

            foreach ($sale->payments()->where('status', Payment::VALID)->get() as $payment) {
                $this->payments->cancel($payment, "Annulation vente {$sale->number}");
            }

            foreach ($sale->lines()->with('product')->get() as $line) {
                $this->stock->receive($sale->depot, $line->product, $line->base_quantity, (float) $line->unit_cost, StockMovementType::SaleReturn, $sale, "Annulation {$sale->number} : $reason");
            }

            $this->packaging->reverseSaleExchange($sale, "Annulation {$sale->number}");

            $sale->forceFill([
                'status' => Sale::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            return $sale;
        });
    }
}
