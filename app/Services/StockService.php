<?php

namespace App\Services;

use App\Enums\StockMovementType as T;
use App\Exceptions\InsufficientStockException;
use App\Models\Depot;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Seul point d'entrée pour modifier un stock.
 *
 * Chaque opération : verrouille le solde (dépôt, produit), contrôle, enregistre un
 * mouvement immuable et met à jour le solde et le CUMP, dans une même transaction.
 *
 * Règles CUMP retenues (à confirmer avec le porteur du projet) :
 * - achat / stock initial : nouveau CUMP = (Q × CUMP + q × coût) / (Q + q) ;
 * - sorties (vente, perte, ajustement −, écart −, transfert envoyé) : au CUMP courant, CUMP inchangé ;
 * - retour de vente : réintègre au coût de la sortie d'origine (recalcule le CUMP) ;
 * - ajustement + / écart + d'inventaire : au CUMP courant, CUMP inchangé ;
 * - transfert reçu : au CUMP du dépôt d'origine (recalcule le CUMP du dépôt de destination).
 */
class StockService
{
    /** Entrée valorisée (stock initial, réception d'achat, retour de vente). */
    public function receive(Depot $depot, Product $product, int $quantity, float $unitCost, T $type = T::PurchaseIn, ?Model $source = null, ?string $reason = null, DateTimeInterface|string|null $occurredAt = null): StockMovement
    {
        if (! $type->isIncoming()) {
            throw new InvalidArgumentException("Le type {$type->value} n'est pas une entrée.");
        }
        if ($unitCost < 0) {
            throw new InvalidArgumentException('Le coût unitaire ne peut pas être négatif.');
        }

        return $this->apply($depot, $product, $quantity, $type, $unitCost, $source, $reason, $occurredAt);
    }

    /** Sortie au CUMP courant (vente, perte, retour fournisseur, ajustement −). */
    public function issue(Depot $depot, Product $product, int $quantity, T $type = T::SaleOut, ?Model $source = null, ?string $reason = null, DateTimeInterface|string|null $occurredAt = null): StockMovement
    {
        if ($type->isIncoming()) {
            throw new InvalidArgumentException("Le type {$type->value} n'est pas une sortie.");
        }

        return $this->apply($depot, $product, -$quantity, $type, null, $source, $reason, $occurredAt);
    }

    /** Ajustement manuel, positif ou négatif, au CUMP courant. */
    public function adjust(Depot $depot, Product $product, int $delta, string $reason, ?Model $source = null, DateTimeInterface|string|null $occurredAt = null, bool $fromInventory = false): ?StockMovement
    {
        if ($delta === 0) {
            return null;
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Un ajustement doit être motivé.');
        }

        $type = match (true) {
            $fromInventory && $delta > 0 => T::InventoryIn,
            $fromInventory => T::InventoryOut,
            $delta > 0 => T::AdjustmentIn,
            default => T::AdjustmentOut,
        };

        return $this->apply($depot, $product, $delta, $type, null, $source, $reason, $occurredAt);
    }

    /** Transfert entre deux dépôts de la même entreprise. @return array{0: StockMovement, 1: StockMovement} */
    public function transfer(Depot $from, Depot $to, Product $product, int $quantity, ?string $reason = null, DateTimeInterface|string|null $occurredAt = null): array
    {
        if ($from->is($to)) {
            throw new InvalidArgumentException('Les dépôts de départ et d\'arrivée doivent être différents.');
        }

        return DB::transaction(function () use ($from, $to, $product, $quantity, $reason, $occurredAt) {
            $group = (string) Str::uuid();
            $out = $this->apply($from, $product, -$quantity, T::TransferOut, null, null, $reason, $occurredAt, $group);
            $in = $this->apply($to, $product, $quantity, T::TransferIn, (float) $out->unit_cost, null, $reason, $occurredAt, $group);

            return [$out, $in];
        });
    }

    public function level(Depot $depot, Product $product): StockLevel
    {
        return StockLevel::firstOrNew(['depot_id' => $depot->id, 'product_id' => $product->id]);
    }

    public function available(Depot $depot, Product $product): int
    {
        return (int) StockLevel::where('depot_id', $depot->id)->where('product_id', $product->id)->value('quantity');
    }

    private function apply(Depot $depot, Product $product, int $signedQty, T $type, ?float $unitCost, ?Model $source, ?string $reason, DateTimeInterface|string|null $occurredAt, ?string $transferGroup = null): StockMovement
    {
        if ($signedQty === 0) {
            throw new InvalidArgumentException('La quantité doit être différente de zéro.');
        }
        if ($signedQty > 0 !== $type->isIncoming()) {
            throw new InvalidArgumentException('Le sens de la quantité ne correspond pas au type de mouvement.');
        }
        $this->assertSameTenant($depot, $product);

        return DB::transaction(function () use ($depot, $product, $signedQty, $type, $unitCost, $source, $reason, $occurredAt, $transferGroup) {
            $level = StockLevel::where('depot_id', $depot->id)->where('product_id', $product->id)->lockForUpdate()->first();

            if (! $level) {
                StockLevel::create(['depot_id' => $depot->id, 'product_id' => $product->id]);
                $level = StockLevel::where('depot_id', $depot->id)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            }

            $before = (int) $level->quantity;
            $avg = (float) $level->avg_cost;
            $after = $before + $signedQty;

            if ($after < 0) {
                throw new InsufficientStockException($product, $before, -$signedQty);
            }

            if ($signedQty > 0 && $type->affectsAverageCost()) {
                $cost = (float) $unitCost;
                $avg = $after > 0 ? (($before * $avg) + ($signedQty * $cost)) / $after : $cost;
            } else {
                $cost = $avg;
            }

            $level->quantity = $after;
            $level->avg_cost = round($avg, 4);
            $level->save();

            return StockMovement::create([
                'depot_id' => $depot->id,
                'product_id' => $product->id,
                'type' => $type,
                'quantity' => $signedQty,
                'unit_cost' => round($cost, 4),
                'quantity_after' => $after,
                'avg_cost_after' => round($avg, 4),
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'transfer_group' => $transferGroup,
                'reason' => $reason,
                'user_id' => auth()->id(),
                'occurred_at' => $occurredAt ? Carbon::parse($occurredAt) : now(),
            ]);
        });
    }

    private function assertSameTenant(Depot $depot, Product $product): void
    {
        $current = app(\App\Support\CurrentTenant::class)->id();

        if ($current === null || $depot->tenant_id !== $current || $product->tenant_id !== $current) {
            throw new LogicException('Dépôt et produit doivent appartenir à l\'entreprise active.');
        }
    }
}
