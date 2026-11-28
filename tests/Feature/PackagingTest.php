<?php

namespace Tests\Feature;

use App\Enums\PackagingMovementType as T;
use App\Exceptions\InsufficientPackagingException;
use App\Exceptions\MissingPackagingException;
use App\Models\Depot;
use App\Models\PackagingMovement;
use App\Models\PackagingType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockLevel;
use App\Models\Tenant;
use App\Services\PackagingService;
use App\Services\StockService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Scénarios de recette 4 et 5, adaptés à la règle du pilote :
 * le client échange toujours ses vides, sinon la vente est refusée.
 */
class PackagingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Depot $depot;

    private PackagingService $packaging;

    private PackagingType $bottle;

    private PackagingType $crate;

    private ProductUnit $beerBottle;

    private ProductUnit $beerCrate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Dépôt A');
        $this->actingAs($this->ownerOf($this->tenant));
        app(CurrentTenant::class)->set($this->tenant);
        $this->depot = Depot::firstOrFail();
        $this->packaging = app(PackagingService::class);

        $this->bottle = PackagingType::create(['name' => 'Bouteille vide 65cl', 'code' => 'BV65']);
        $this->crate = PackagingType::create(['name' => 'Casier 12', 'code' => 'CA12']);

        $beer = Product::create(['name' => 'Bière blonde 65cl', 'reference' => 'BB65']);
        $this->beerBottle = $beer->units()->create(['name' => 'Bouteille', 'factor' => 1, 'price' => 650]);
        $this->beerCrate = $beer->units()->create(['name' => 'Casier', 'factor' => 12, 'price' => 7500]);
        $this->beerBottle->packagings()->create(['packaging_type_id' => $this->bottle->id, 'quantity' => 1]);
        $this->beerCrate->packagings()->createMany([
            ['packaging_type_id' => $this->bottle->id, 'quantity' => 12],
            ['packaging_type_id' => $this->crate->id, 'quantity' => 1],
        ]);
    }

    public function test_required_empties_follow_the_unit_rules(): void
    {
        $required = $this->packaging->required([[$this->beerCrate->id, 2], [$this->beerBottle->id, 3]]);

        $this->assertSame([$this->bottle->id => 27, $this->crate->id => 2], $required);
    }

    public function test_non_returnable_product_needs_no_empties(): void
    {
        $water = Product::create(['name' => 'Eau 1,5L', 'reference' => 'EAU']);
        $pack = $water->units()->create(['name' => 'Pack', 'factor' => 6, 'price' => 2300]);

        $this->assertSame([], $this->packaging->required([[$pack->id, 4]]));
    }

    public function test_sale_without_enough_empties_is_refused_and_nothing_is_recorded(): void
    {
        try {
            $this->packaging->collectForSale($this->depot, [[$this->beerCrate->id, 1]], $this->depot, [$this->bottle->id => 10, $this->crate->id => 1]);
            $this->fail('La vente aurait dû être refusée.');
        } catch (MissingPackagingException $e) {
            $this->assertSame(['Bouteille vide 65cl' => 2], $e->missing);
            $this->assertStringContainsString('2 × Bouteille vide 65cl', $e->getMessage());
        }

        $this->assertSame(0, PackagingMovement::count());
    }

    public function test_crate_sale_exchange_adds_empties_to_depot_and_leaves_full_stock_alone(): void
    {
        $product = $this->beerCrate->product;
        app(StockService::class)->receive($this->depot, $product, 24, 500);

        $this->packaging->collectForSale($this->depot, [[$this->beerCrate->id, 1]], $this->depot, [$this->bottle->id => 12, $this->crate->id => 1]);

        $this->assertSame(12, $this->packaging->available($this->depot, $this->bottle));
        $this->assertSame(1, $this->packaging->available($this->depot, $this->crate));
        $this->assertSame(24, (int) StockLevel::query()->value('quantity'), 'le stock de boissons pleines est indépendant');
    }

    public function test_cancelling_a_sale_gives_the_empties_back(): void
    {
        $this->packaging->collectForSale($this->depot, [[$this->beerCrate->id, 2]], $this->depot);
        $this->packaging->reverseSaleExchange($this->depot, 'Annulation');

        $this->assertSame(0, $this->packaging->available($this->depot, $this->bottle));
        $this->assertSame(0, $this->packaging->available($this->depot, $this->crate));
        $this->assertSame(T::CustomerExchangeCancel, PackagingMovement::latest('id')->first()->type);

        // une seconde annulation ne rend rien de plus
        $this->packaging->reverseSaleExchange($this->depot);
        $this->assertSame(4, PackagingMovement::count());
    }

    public function test_handing_empties_to_supplier_is_limited_to_depot_stock(): void
    {
        $this->packaging->receive($this->depot, $this->bottle, 48, T::Initial);
        $this->packaging->issue($this->depot, $this->bottle, 36, T::SupplierOut, 'Livraison fournisseur');

        $this->assertSame(12, $this->packaging->available($this->depot, $this->bottle));

        $this->expectException(InsufficientPackagingException::class);
        $this->packaging->issue($this->depot, $this->bottle, 13, T::SupplierOut);
    }

    public function test_breakage_adjustment_and_transfer(): void
    {
        $annex = Depot::create(['name' => 'Annexe', 'code' => 'ANX']);
        $this->packaging->receive($this->depot, $this->crate, 10);
        $this->packaging->issue($this->depot, $this->crate, 1, T::Loss, 'Casier cassé');
        $this->packaging->adjust($this->depot, $this->crate, 2, 'Recomptage');
        $this->packaging->transfer($this->depot, $annex, $this->crate, 4);

        $this->assertSame(7, $this->packaging->available($this->depot, $this->crate));
        $this->assertSame(4, $this->packaging->available($annex, $this->crate));
        $this->assertSame(11, (int) PackagingMovement::where('packaging_type_id', $this->crate->id)->sum('quantity'));
    }

    public function test_packaging_movements_are_immutable(): void
    {
        $m = $this->packaging->receive($this->depot, $this->crate, 3);

        $this->expectException(LogicException::class);
        $m->update(['quantity' => 30]);
    }

    public function test_packaging_is_isolated_between_tenants(): void
    {
        $this->packaging->receive($this->depot, $this->crate, 3);
        $other = $this->makeTenant('Dépôt B');
        $otherDepot = $this->inTenant($other, fn () => Depot::firstOrFail());

        $this->inTenant($other, function () use ($otherDepot) {
            $this->assertSame(0, PackagingType::count());
            $this->assertSame(0, PackagingMovement::count());
            $this->expectException(LogicException::class);
            $this->packaging->receive($otherDepot, $this->crate, 1);
        });
    }
}
