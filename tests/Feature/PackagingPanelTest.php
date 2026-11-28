<?php

namespace Tests\Feature;

use App\Filament\Resources\PackagingLevels\Pages\ListPackagingLevels;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Depot;
use App\Models\PackagingType;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PackagingService;
use App\Services\TenantProvisioner;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PackagingPanelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private PackagingType $bottle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Dépôt A');
        $this->enter($this->ownerOf($this->tenant));
        $this->bottle = PackagingType::create(['name' => 'Bouteille vide 65cl', 'code' => 'BV65']);
    }

    private function enter(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->tenant);
        app(CurrentTenant::class)->set($this->tenant);
    }

    public function test_packaging_pages_open(): void
    {
        app(PackagingService::class)->receive(Depot::first(), $this->bottle, 24);
        $slug = $this->tenant->slug;

        foreach (['packaging-types', 'packaging-levels', 'packaging-movements'] as $path) {
            $this->get("/app/$slug/$path")->assertOk();
        }
        $this->get("/app/$slug/packaging-levels")->assertSee('Bouteille vide 65cl');
    }

    public function test_product_form_saves_packaging_rules_per_unit(): void
    {
        $crate = PackagingType::create(['name' => 'Casier 12', 'code' => 'CA12']);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Bière blonde 65cl', 'reference' => 'BB65', 'base_unit_name' => 'Bouteille',
                'units' => [
                    ['name' => 'Bouteille', 'factor' => 1, 'price' => 650, 'packagings' => [
                        ['packaging_type_id' => $this->bottle->id, 'quantity' => 1],
                    ]],
                    ['name' => 'Casier', 'factor' => 12, 'price' => 7500, 'packagings' => [
                        ['packaging_type_id' => $this->bottle->id, 'quantity' => 12],
                        ['packaging_type_id' => $crate->id, 'quantity' => 1],
                    ]],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $unit = Product::where('reference', 'BB65')->firstOrFail()->units()->where('name', 'Casier')->firstOrFail();
        $this->assertSame([$this->bottle->id => 12, $crate->id => 1], app(PackagingService::class)->required([[$unit->id, 1]]));
    }

    public function test_handing_more_empties_than_available_shows_an_error(): void
    {
        app(PackagingService::class)->receive(Depot::first(), $this->bottle, 10);

        Livewire::test(ListPackagingLevels::class)
            ->callAction('supplier', data: [
                'depot_id' => Depot::first()->id, 'packaging_type_id' => $this->bottle->id, 'quantity' => 12, 'occurred_at' => now(),
            ])
            ->assertNotified('Opération refusée');

        $this->assertSame(10, app(PackagingService::class)->available(Depot::first(), $this->bottle));
    }

    public function test_cashier_sees_empties_but_cannot_adjust(): void
    {
        $cashier = User::factory()->create();
        app(TenantProvisioner::class)->addMember($this->tenant, $cashier, Roles::CAISSIER);
        $this->enter($cashier);

        $this->get("/app/{$this->tenant->slug}/packaging-levels")->assertOk();
        Livewire::test(ListPackagingLevels::class)->assertActionHidden('adjust');
    }
}
