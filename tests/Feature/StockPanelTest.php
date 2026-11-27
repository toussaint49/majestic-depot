<?php

namespace Tests\Feature;

use App\Filament\Resources\Inventories\Pages\EditInventory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Models\Depot;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\StockService;
use App\Services\TenantProvisioner;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockPanelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Dépôt A');
        $this->enter($this->ownerOf($this->tenant));
    }

    private function enter(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->tenant);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function beer(): Product
    {
        $p = Product::create(['name' => 'Bière blonde 65cl', 'reference' => 'BB65']);
        $p->units()->create(['name' => 'Bouteille', 'factor' => 1, 'price' => 650]);
        $p->units()->create(['name' => 'Casier', 'factor' => 12, 'price' => 7500]);

        return $p;
    }

    public function test_all_catalog_and_stock_pages_open(): void
    {
        $beer = $this->beer();
        app(StockService::class)->receive(Depot::first(), $beer, 30, 500);
        $inventory = app(InventoryService::class)->start(Depot::first());
        app(InventoryService::class)->setCounts($inventory, [$beer->id => 28]);
        $slug = $this->tenant->slug;
        $this->get("/app/$slug/stock-levels")->assertOk()->assertSee('2 Casier + 6 Bouteille');
        $this->get("/app/$slug/inventories/{$inventory->id}")->assertOk();
        $this->get("/app/$slug/inventories/{$inventory->id}/edit")->assertOk();
        app(InventoryService::class)->validate($inventory);
        $this->get("/app/$slug/inventories/{$inventory->id}")->assertOk()->assertSee('−2 Bouteille');
        foreach (['products', 'products/create', 'categories', 'brands', 'stock-levels', 'stock-movements', 'inventories', 'inventories/create'] as $path) {
            $this->get("/app/$slug/$path")->assertOk();
        }
    }

    public function test_product_is_created_with_its_units(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Eau 1,5L', 'reference' => 'EAU15', 'base_unit_name' => 'Bouteille',
                'units' => [
                    ['name' => 'Bouteille', 'factor' => 1, 'price' => 400],
                    ['name' => 'Pack', 'factor' => 6, 'price' => 2300],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('reference', 'EAU15')->firstOrFail();
        $this->assertSame([1, 6], $product->units()->pluck('factor')->all());
        $this->assertSame($this->tenant->id, $product->units()->first()->tenant_id);
    }

    public function test_product_needs_exactly_one_base_unit(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Sans base', 'reference' => 'X1',
                'units' => [['name' => 'Casier', 'factor' => 12, 'price' => 7000]],
            ])
            ->call('create')
            ->assertHasFormErrors(['units']);
    }

    public function test_stock_entry_by_crate_from_the_stock_page(): void
    {
        $beer = $this->beer();
        $crate = $beer->units()->where('name', 'Casier')->first();

        Livewire::test(ListStockLevels::class)
            ->callAction('initial', data: [
                'depot_id' => Depot::first()->id, 'product_id' => $beer->id, 'quantity' => 3,
                'product_unit_id' => $crate->id, 'unit_cost' => 500, 'type' => 'initial', 'occurred_at' => now(),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Entrée enregistrée');

        $this->assertSame(36, app(StockService::class)->available(Depot::first(), $beer));
    }

    public function test_loss_above_stock_is_refused_with_a_message(): void
    {
        $beer = $this->beer();
        $bottle = $beer->units()->where('factor', 1)->first();
        app(StockService::class)->receive(Depot::first(), $beer, 5, 500);

        Livewire::test(ListStockLevels::class)
            ->callAction('loss', data: [
                'depot_id' => Depot::first()->id, 'product_id' => $beer->id, 'quantity' => 6,
                'product_unit_id' => $bottle->id, 'reason' => 'Casse', 'occurred_at' => now(),
            ])
            ->assertNotified('Opération refusée');

        $this->assertSame(5, app(StockService::class)->available(Depot::first(), $beer));
    }

    public function test_cashier_cannot_adjust_stock_or_validate_inventory(): void
    {
        $cashier = User::factory()->create();
        app(TenantProvisioner::class)->addMember($this->tenant, $cashier, Roles::CAISSIER);
        $inventory = app(InventoryService::class)->start(Depot::first());
        $this->enter($cashier);

        $this->assertFalse($cashier->can('stocks.validate'));
        Livewire::test(ListStockLevels::class)->assertActionHidden('initial');
        $this->get("/app/{$this->tenant->slug}/inventories/{$inventory->id}/edit")->assertForbidden();
    }

    public function test_storekeeper_counts_but_manager_validates(): void
    {
        $beer = $this->beer();
        app(StockService::class)->receive(Depot::first(), $beer, 24, 500);
        $storekeeper = User::factory()->create();
        app(TenantProvisioner::class)->addMember($this->tenant, $storekeeper, Roles::MAGASINIER);
        $inventory = app(InventoryService::class)->start(Depot::first());

        $this->enter($storekeeper);
        Livewire::test(EditInventory::class, ['record' => $inventory->getRouteKey()])
            ->fillForm(['lines' => [['product_id' => $beer->id, 'counted_quantity' => 22]]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertActionHidden('validate');

        $this->enter($this->ownerOf($this->tenant));
        Livewire::test(EditInventory::class, ['record' => $inventory->getRouteKey()])
            ->callAction('validate');

        $this->assertSame(Inventory::VALIDATED, $inventory->fresh()->status);
        $this->assertSame(22, app(StockService::class)->available(Depot::first(), $beer));
    }
}
