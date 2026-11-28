<?php

namespace App\Services;

use App\Models\Depot;
use App\Models\Inventory;
use App\Models\StockLevel;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Inventaire physique : on saisit les quantités comptées, puis la validation
 * fige le stock théorique, calcule les écarts et passe les ajustements.
 */
class InventoryService
{
    public function __construct(private StockService $stock, private SequenceService $sequences) {}

    public function start(Depot $depot, ?\DateTimeInterface $countedAt = null, ?string $notes = null): Inventory
    {
        $inventory = new Inventory([
            'depot_id' => $depot->id,
            'number' => $this->sequences->next('inventory'),
            'counted_at' => $countedAt ?? now(),
            'notes' => $notes,
        ]);
        $inventory->created_by = auth()->id();
        $inventory->save();

        return $inventory;
    }

    /** @param array<int, int> $counts product_id => quantité comptée (unité de base) */
    public function setCounts(Inventory $inventory, array $counts): void
    {
        $this->assertDraft($inventory);

        foreach ($counts as $productId => $qty) {
            if ($qty < 0) {
                throw new LogicException('Une quantité comptée ne peut pas être négative.');
            }
            $inventory->lines()->updateOrCreate(['product_id' => $productId], ['counted_quantity' => $qty]);
        }
    }

    public function validate(Inventory $inventory): Inventory
    {
        return DB::transaction(function () use ($inventory) {
            $inventory = Inventory::whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($inventory);

            foreach ($inventory->lines()->with('product')->get() as $line) {
                $level = StockLevel::where('depot_id', $inventory->depot_id)->where('product_id', $line->product_id)->first();
                $expected = (int) ($level?->quantity ?? 0);
                $difference = $line->counted_quantity - $expected;

                $line->expected_quantity = $expected;
                $line->difference = $difference;
                $line->unit_cost = $level?->avg_cost ?? 0;
                $line->save();

                $this->stock->adjust(
                    $inventory->depot,
                    $line->product,
                    $difference,
                    "Inventaire {$inventory->number}",
                    $inventory,
                    $inventory->counted_at,
                    fromInventory: true,
                );
            }

            $inventory->status = Inventory::VALIDATED;
            $inventory->validated_by = auth()->id();
            $inventory->validated_at = now();
            $inventory->save();

            return $inventory;
        });
    }

    public function cancel(Inventory $inventory): void
    {
        $this->assertDraft($inventory);
        $inventory->status = Inventory::CANCELLED;
        $inventory->save();
    }

    private function assertDraft(Inventory $inventory): void
    {
        if (! $inventory->isDraft()) {
            throw new LogicException('Seul un inventaire en brouillon peut être modifié.');
        }
    }
}
