<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Sales\Pages\CreateSale;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Models\Customer;
use App\Models\Depot;
use App\Models\PackagingType;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioner;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalePanelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ProductUnit $crate;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Dépôt A');
        $this->enter($this->ownerOf($this->tenant));

        $empty = PackagingType::create(['name' => 'Bouteille vide 65cl', 'code' => 'BV65']);
        $beer = Product::create(['name' => 'Bière blonde 65cl', 'reference' => 'BB65']);
        $beer->units()->create(['name' => 'Bouteille', 'factor' => 1, 'price' => 650]);
        $this->crate = $beer->units()->create(['name' => 'Casier', 'factor' => 12, 'price' => 7500]);
        $this->crate->packagings()->create(['packaging_type_id' => $empty->id, 'quantity' => 12]);
        app(StockService::class)->receive(Depot::first(), $beer, 240, 500);
        $this->customer = Customer::create(['name' => 'Bar La Joie']);
        $this->openCash();
    }

    private function enter(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->tenant);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function account(string $kind): int
    {
        return PaymentAccount::where('kind', $kind)->value('id');
    }

    public function test_cashier_records_a_mixed_payment_sale_from_the_form(): void
    {
        $cashier = User::factory()->create();
        app(TenantProvisioner::class)->addMember($this->tenant, $cashier, Roles::CAISSIER);
        $this->enter($cashier);

        Livewire::test(CreateSale::class)
            ->fillForm([
                'depot_id' => Depot::first()->id,
                'customer_id' => $this->customer->id,
                'occurred_at' => now(),
                'lines' => [['product_unit_id' => $this->crate->id, 'quantity' => 12]],
                'empties_returned' => true,
                'payments' => [
                    ['payment_account_id' => $this->account('cash'), 'amount' => 40000],
                    ['payment_account_id' => $this->account('mtn_momo'), 'amount' => 30000, 'reference' => 'MP123'],
                ],
            ])
            ->assertSee('90 000 FCFA')
            ->assertSee('144 × Bouteille vide 65cl')
            ->call('create')
            ->assertHasNoFormErrors();

        $sale = Sale::firstOrFail();
        $this->assertSame(90000, $sale->total);
        $this->assertSame(20000, $sale->balance());
        $this->assertSame($cashier->id, $sale->user_id);
    }

    public function test_form_refuses_sale_when_empties_are_not_returned(): void
    {
        Livewire::test(CreateSale::class)
            ->fillForm([
                'depot_id' => Depot::first()->id,
                'occurred_at' => now(),
                'lines' => [['product_unit_id' => $this->crate->id, 'quantity' => 1]],
                'empties_returned' => false,
                'payments' => [['payment_account_id' => $this->account('cash'), 'amount' => 7500]],
            ])
            ->call('create')
            ->assertHasFormErrors(['empties_returned' => 'accepted']);

        $this->assertSame(0, Sale::count());
    }

    public function test_form_shows_service_errors_without_saving(): void
    {
        Livewire::test(CreateSale::class)
            ->fillForm([
                'depot_id' => Depot::first()->id,
                'occurred_at' => now(),
                'lines' => [['product_unit_id' => $this->crate->id, 'quantity' => 1]],
                'empties_returned' => true,
                'payments' => [['payment_account_id' => $this->account('cash'), 'amount' => 5000]],
            ])
            ->call('create')
            ->assertNotified('Vente refusée');

        $this->assertSame(0, Sale::count());
    }

    private function creditSale(): Sale
    {
        return app(SaleService::class)->create([
            'depot_id' => Depot::first()->id, 'customer_id' => $this->customer->id, 'empties_returned' => true,
            'lines' => [['product_unit_id' => $this->crate->id, 'quantity' => 2]],
            'payments' => [['payment_account_id' => $this->account('cash'), 'amount' => 5000]],
        ]);
    }

    public function test_pages_tickets_and_statement_render(): void
    {
        $sale = $this->creditSale();
        $slug = $this->tenant->slug;

        foreach (['sales', 'sales/create', "sales/{$sale->id}", 'customers', "customers/{$this->customer->id}", 'payments', 'payment-accounts'] as $path) {
            $this->get("/app/$slug/$path")->assertOk();
        }

        $this->get("/t/$slug/ventes/{$sale->id}/ticket")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get("/t/$slug/clients/{$this->customer->id}/releve")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_documents_of_another_tenant_are_not_reachable(): void
    {
        $sale = $this->creditSale();
        $other = $this->makeTenant('Dépôt B');
        $this->actingAs($this->ownerOf($other));

        $this->get("/t/{$other->slug}/ventes/{$sale->id}/ticket")->assertNotFound();
        $this->get("/t/{$this->tenant->slug}/ventes/{$sale->id}/ticket")->assertNotFound();
    }

    public function test_settlement_and_cancellation_from_the_sale_page(): void
    {
        $sale = $this->creditSale();

        Livewire::test(ViewCustomer::class, ['record' => $this->customer->getRouteKey()])
            ->callAction('settle', data: ['amount' => 4000, 'payment_account_id' => $this->account('orange_money'), 'occurred_at' => now()])
            ->assertNotified();
        $this->assertSame(6000, $sale->fresh()->balance());

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->callAction('cancel', data: ['reason' => 'Test'])
            ->assertNotified('Annulation refusée');

        $this->assertFalse($sale->fresh()->isCancelled());
    }

    public function test_cashier_cannot_cancel_a_sale(): void
    {
        $sale = $this->creditSale();
        $cashier = User::factory()->create();
        app(TenantProvisioner::class)->addMember($this->tenant, $cashier, Roles::CAISSIER);
        $this->enter($cashier);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])->assertActionHidden('cancel');
    }
}
