<?php

namespace Tests\Feature;

use App\Enums\StockMovementType as T;
use App\Exceptions\InsufficientStockException;
use App\Models\Depot;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Services\InventoryService;
use App\Services\StockService;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Scénarios de recette 2, 3 et 7 du cahier des charges, plus inventaires et transferts.
 */
class StockTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Depot $depot;

    private Product $beer;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Dépôt A');
        $this->actingAs($this->ownerOf($this->tenant));
        app(\App\Support\CurrentTenant::class)->set($this->tenant);

        $this->depot = Depot::firstOrFail();
        $this->beer = $this->makeProduct('Castel 65cl', 'CAS65', [['Bouteille', 1, 650], ['Casier', 12, 7500]]);
        $this->stock = app(StockService::class);
    }

    private function makeProduct(string $name, string $ref, array $units): Product
    {
        $product = Product::create(['name' => $name, 'reference' => $ref]);
        foreach ($units as [$unit, $factor, $price]) {
            $product->units()->create(['name' => $unit, 'factor' => $factor, 'price' => $price]);
        }

        return $product->load('units');
    }

    public function test_product_sold_by_bottle_and_crate_converts_to_base_units(): void
    {
        $crate = $this->beer->units->firstWhere('name', 'Casier');

        $this->assertSame(36, Quantity::toBase(3, $crate->factor));
        $this->assertSame('2 Casier + 6 Bouteille', Quantity::format($this->beer, 30));
        $this->assertSame('5 Bouteille', Quantity::format($this->beer, 5));
        $this->assertSame('1 Casier', Quantity::format($this->beer, 12));
        $this->assertSame(1, $this->beer->baseUnit()->factor);
    }

    public function test_receipt_increases_stock_and_recomputes_weighted_average_cost(): void
    {
        $this->stock->receive($this->depot, $this->beer, 24, 500);   // 24 × 500
        $this->stock->receive($this->depot, $this->beer, 12, 560);   // 12 × 560

        $level = StockLevel::where('product_id', $this->beer->id)->firstOrFail();
        $this->assertSame(36, $level->quantity);
        $this->assertEqualsWithDelta(520.0, (float) $level->avg_cost, 0.0001); // (12000 + 6720) / 36
        $this->assertEquals(18720, $level->value());
    }

    public function test_issues_use_current_average_cost_and_keep_it(): void
    {
        $this->stock->receive($this->depot, $this->beer, 24, 500);
        $this->stock->receive($this->depot, $this->beer, 12, 560);

        $out = $this->stock->issue($this->depot, $this->beer, 10, T::SaleOut);
        $loss = $this->stock->issue($this->depot, $this->beer, 2, T::Loss, reason: 'Casse');

        $this->assertEqualsWithDelta(520.0, (float) $out->unit_cost, 0.0001);
        $this->assertSame(-10, $out->quantity);
        $this->assertSame(24, $loss->quantity_after);
        $this->assertEqualsWithDelta(520.0, (float) $loss->avg_cost_after, 0.0001);
    }

    public function test_selling_more_than_available_is_blocked_and_nothing_is_written(): void
    {
        $this->stock->receive($this->depot, $this->beer, 10, 500);

        try {
            $this->stock->issue($this->depot, $this->beer, 12, T::SaleOut);
            $this->fail('La vente aurait dû être refusée.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('10 Bouteille disponible', $e->getMessage());
            $this->assertStringContainsString('1 Casier demandé', $e->getMessage());
        }

        $this->assertSame(10, $this->stock->available($this->depot, $this->beer));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_balance_always_equals_sum_of_movements(): void
    {
        $this->stock->receive($this->depot, $this->beer, 48, 500);
        $this->stock->issue($this->depot, $this->beer, 13);
        $this->stock->adjust($this->depot, $this->beer, -1, 'Bouteille fêlée');
        $this->stock->adjust($this->depot, $this->beer, 3, 'Oubli de saisie');

        $this->assertSame(37, $this->stock->available($this->depot, $this->beer));
        $this->assertSame(37, (int) StockMovement::where('product_id', $this->beer->id)->sum('quantity'));
    }

    public function test_adjustment_requires_a_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->stock->adjust($this->depot, $this->beer, 2, '  ');
    }

    public function test_movements_are_immutable(): void
    {
        $movement = $this->stock->receive($this->depot, $this->beer, 5, 500);

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_transfer_moves_stock_and_cost_between_depots(): void
    {
        $annex = Depot::create(['name' => 'Annexe', 'code' => 'ANX']);
        $this->stock->receive($this->depot, $this->beer, 24, 500);
        $this->stock->receive($annex, $this->beer, 12, 620);

        [$out, $in] = $this->stock->transfer($this->depot, $annex, $this->beer, 12);

        $this->assertSame(12, $this->stock->available($this->depot, $this->beer));
        $this->assertSame(24, $this->stock->available($annex, $this->beer));
        $this->assertSame($out->transfer_group, $in->transfer_group);
        $this->assertEqualsWithDelta(560.0, (float) StockLevel::where('depot_id', $annex->id)->value('avg_cost'), 0.0001);
    }

    public function test_transfer_beyond_stock_is_blocked_on_both_sides(): void
    {
        $annex = Depot::create(['name' => 'Annexe', 'code' => 'ANX']);
        $this->stock->receive($this->depot, $this->beer, 5, 500);

        try {
            $this->stock->transfer($this->depot, $annex, $this->beer, 6);
        } catch (InsufficientStockException) {
        }

        $this->assertSame(5, $this->stock->available($this->depot, $this->beer));
        $this->assertSame(0, $this->stock->available($annex, $this->beer));
    }

    public function test_inventory_validation_books_differences_and_locks_the_inventory(): void
    {
        $water = $this->makeProduct('Supermont 1,5L', 'SUP15', [['Bouteille', 1, 400], ['Pack', 6, 2300]]);
        $this->stock->receive($this->depot, $this->beer, 36, 500);
        $this->stock->receive($this->depot, $water, 12, 300);

        $service = app(InventoryService::class);
        $inventory = $service->start($this->depot);
        $service->setCounts($inventory, [$this->beer->id => 33, $water->id => 14]);
        $service->validate($inventory);

        $this->assertSame(33, $this->stock->available($this->depot, $this->beer));
        $this->assertSame(14, $this->stock->available($this->depot, $water));
        $this->assertSame('INV-000001', $inventory->number);

        $line = $inventory->lines()->where('product_id', $this->beer->id)->first();
        $this->assertSame(36, $line->expected_quantity);
        $this->assertSame(-3, $line->difference);
        $this->assertSame(T::InventoryOut, StockMovement::where('source_id', $inventory->id)->where('product_id', $this->beer->id)->first()->type);

        $this->assertSame(Inventory::VALIDATED, $inventory->fresh()->status);
        $this->expectException(LogicException::class);
        $service->setCounts($inventory->fresh(), [$this->beer->id => 40]);
    }

    public function test_stock_operations_refuse_records_of_another_tenant(): void
    {
        $other = $this->makeTenant('Dépôt B');
        $otherDepot = $this->inTenant($other, fn () => Depot::firstOrFail());

        $this->expectException(LogicException::class);
        $this->stock->receive($otherDepot, $this->beer, 5, 500);
    }

    public function test_products_and_stock_are_isolated_between_tenants(): void
    {
        $this->stock->receive($this->depot, $this->beer, 5, 500);
        $other = $this->makeTenant('Dépôt B');

        $this->inTenant($other, function () {
            $this->assertSame(0, Product::count());
            $this->assertSame(0, StockLevel::count());
            $this->assertSame(0, StockMovement::count());
        });
    }
}
