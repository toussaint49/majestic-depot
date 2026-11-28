<?php

namespace Tests\Feature;

use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Pages\ViewPurchase;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashSessionService;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchasePanelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ProductUnit $crate;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Dépôt A');
        $this->enter($this->ownerOf($this->tenant));

        $beer = Product::create(['name' => 'Bière blonde 65cl', 'reference' => 'BB65']);
        $beer->units()->create(['name' => 'Bouteille', 'factor' => 1, 'price' => 650]);
        $this->crate = $beer->units()->create(['name' => 'Casier', 'factor' => 12, 'price' => 7500]);
        $this->supplier = Supplier::create(['name' => 'Brasserie du Littoral']);
    }

    private function enter(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->tenant);
        app(CurrentTenant::class)->set($this->tenant);
    }

    public function test_purchase_can_be_entered_and_paid_from_the_panel(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'supplier_id' => $this->supplier->id,
                'invoice_number' => 'F-100',
                'lines' => [['product_unit_id' => $this->crate->id, 'quantity' => 5, 'unit_price' => 6600]],
                'payments' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $purchase = Purchase::firstOrFail();
        $this->assertSame(33000, $purchase->total);
        $this->assertSame(60, StockLevel::firstOrFail()->quantity);

        $session = $this->openCash();
        $cash = PaymentAccount::where('kind', 'cash')->firstOrFail();
        app(CashSessionService::class)->deposit($session, $cash, 40000, 'Apport');

        Livewire::test(ViewPurchase::class, ['record' => $purchase->getRouteKey()])
            ->callAction('pay', ['payment_account_id' => $cash->id, 'amount' => 33000])
            ->assertHasNoActionErrors();

        $this->assertSame(0, $purchase->refresh()->balance());
        Livewire::test(ListPurchases::class)->assertCanSeeTableRecords([$purchase]);
        Livewire::test(ListSuppliers::class)->assertCanSeeTableRecords([$this->supplier]);
        Livewire::test(ViewSupplier::class, ['record' => $this->supplier->getRouteKey()])->assertOk();
    }

    public function test_cashier_cannot_see_purchases(): void
    {
        $cashier = User::factory()->create();
        $this->tenant->users()->attach($cashier, ['is_active' => true]);
        app(CurrentTenant::class)->set($this->tenant);
        $cashier->assignRole(Roles::CAISSIER);

        $this->enter($cashier);
        $this->get(PurchaseResource::getUrl('index', tenant: $this->tenant))->assertForbidden();
    }
}
