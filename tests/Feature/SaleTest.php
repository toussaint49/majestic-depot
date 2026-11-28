<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\MissingPackagingException;
use App\Exceptions\SaleException;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Depot;
use App\Models\PackagingType;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Services\PackagingService;
use App\Services\PaymentService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scénarios de recette 6 (paiement mixte), 7 (stock insuffisant) et 10 (annulation),
 * plus crédit 14 jours, règlements et règle « pas de vides, pas de vente ».
 */
class SaleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Depot $depot;

    private ProductUnit $bottle;

    private ProductUnit $crate;

    private ProductUnit $waterPack;

    private PackagingType $emptyBottle;

    private PackagingType $emptyCrate;

    private PaymentAccount $cash;

    private PaymentAccount $momo;

    private PaymentAccount $om;

    private Customer $customer;

    private SaleService $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Dépôt A');
        $this->actingAs($this->ownerOf($this->tenant));
        app(CurrentTenant::class)->set($this->tenant);
        $this->depot = Depot::firstOrFail();
        $this->sales = app(SaleService::class);

        $this->emptyBottle = PackagingType::create(['name' => 'Bouteille vide 65cl', 'code' => 'BV65']);
        $this->emptyCrate = PackagingType::create(['name' => 'Casier 12', 'code' => 'CA12']);

        $beer = Product::create(['name' => 'Bière blonde 65cl', 'reference' => 'BB65']);
        $this->bottle = $beer->units()->create(['name' => 'Bouteille', 'factor' => 1, 'price' => 650]);
        $this->crate = $beer->units()->create(['name' => 'Casier', 'factor' => 12, 'price' => 7500]);
        $this->bottle->packagings()->create(['packaging_type_id' => $this->emptyBottle->id, 'quantity' => 1]);
        $this->crate->packagings()->createMany([
            ['packaging_type_id' => $this->emptyBottle->id, 'quantity' => 12],
            ['packaging_type_id' => $this->emptyCrate->id, 'quantity' => 1],
        ]);
        app(StockService::class)->receive($this->depot, $beer, 240, 500); // 20 casiers

        $water = Product::create(['name' => 'Eau 1,5L', 'reference' => 'EAU15']);
        $water->units()->create(['name' => 'Bouteille', 'factor' => 1, 'price' => 400]);
        $this->waterPack = $water->units()->create(['name' => 'Pack', 'factor' => 6, 'price' => 2250]);
        app(StockService::class)->receive($this->depot, $water, 120, 300);

        $this->cash = PaymentAccount::where('kind', 'cash')->firstOrFail();
        $this->momo = PaymentAccount::where('kind', 'mtn_momo')->firstOrFail();
        $this->om = PaymentAccount::where('kind', 'orange_money')->firstOrFail();
        $this->customer = Customer::create(['name' => 'Bar La Joie', 'phone' => '690000000']);
        $this->openCash();
    }

    private function sell(array $lines, array $payments = [], array $extra = []): Sale
    {
        return $this->sales->create($extra + [
            'depot_id' => $this->depot->id,
            'customer_id' => $this->customer->id,
            'empties_returned' => true,
            'lines' => $lines,
            'payments' => $payments,
        ]);
    }

    public function test_mixed_payment_separates_cash_received_from_credit(): void
    {
        // 12 casiers × 7 500 = 90 000 FCFA : 40 000 espèces + 30 000 MoMo + 20 000 à crédit
        $sale = $this->sell(
            [['product_unit_id' => $this->crate->id, 'quantity' => 12]],
            [
                ['payment_account_id' => $this->cash->id, 'amount' => 40000],
                ['payment_account_id' => $this->momo->id, 'amount' => 30000, 'reference' => 'MP2611.1234'],
            ],
        );

        $this->assertSame(90000, $sale->total);
        $this->assertSame(70000, $sale->paid_at_sale);
        $this->assertSame(20000, $sale->credit_amount);
        $this->assertSame(20000, $sale->balance());
        $this->assertSame(70000, (int) AccountTransaction::sum('amount'), 'seul l\'argent reçu est encaissé');
        $this->assertSame(40000, $this->cash->balance());
        $this->assertSame(30000, $this->momo->balance());
        $this->assertSame(20000, $this->customer->balance());
        $this->assertSame(today()->addDays(14)->toDateString(), $sale->due_date->toDateString());
    }

    public function test_sale_updates_stock_empties_and_keeps_price_snapshot(): void
    {
        $sale = $this->sell(
            [['product_unit_id' => $this->crate->id, 'quantity' => 2], ['product_unit_id' => $this->bottle->id, 'quantity' => 3]],
            [['payment_account_id' => $this->cash->id, 'amount' => 16950]],
        );

        $this->assertSame(240 - 27, app(StockService::class)->available($this->depot, $this->crate->product));
        $this->assertSame(27, app(PackagingService::class)->available($this->depot, $this->emptyBottle));
        $this->assertSame(2, app(PackagingService::class)->available($this->depot, $this->emptyCrate));

        $this->crate->update(['price' => 8000]);
        $line = $sale->lines()->where('product_unit_id', $this->crate->id)->first();
        $this->assertSame(7500, $line->unit_price);
        $this->assertSame(15000, $line->line_total);
        $this->assertSame(12, $line->factor);
        $this->assertEqualsWithDelta(500.0, (float) $line->unit_cost, 0.001);
        $this->assertSame(0, $sale->fresh()->balance());
    }

    public function test_sale_above_stock_is_blocked_and_nothing_is_saved(): void
    {
        try {
            $this->sell([['product_unit_id' => $this->crate->id, 'quantity' => 21]], [['payment_account_id' => $this->cash->id, 'amount' => 1000]]);
            $this->fail('Vente au-delà du stock acceptée.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, AccountTransaction::count());
        $this->assertSame(240, app(StockService::class)->available($this->depot, $this->crate->product));
    }

    public function test_sale_without_empties_is_refused(): void
    {
        $this->expectException(MissingPackagingException::class);

        try {
            $this->sell([['product_unit_id' => $this->crate->id, 'quantity' => 1]], [['payment_account_id' => $this->cash->id, 'amount' => 7500]], ['empties_returned' => false]);
        } finally {
            $this->assertSame(0, Sale::count());
            $this->assertSame(240, app(StockService::class)->available($this->depot, $this->crate->product));
        }
    }

    public function test_non_returnable_product_sells_without_empties(): void
    {
        $sale = $this->sell([['product_unit_id' => $this->waterPack->id, 'quantity' => 2]], [['payment_account_id' => $this->om->id, 'amount' => 4500]], ['empties_returned' => false, 'customer_id' => null]);

        $this->assertSame(4500, $sale->total);
        $this->assertNull($sale->customer_id);
    }

    public function test_credit_requires_a_customer_and_overpayment_is_refused(): void
    {
        try {
            $this->sell([['product_unit_id' => $this->waterPack->id, 'quantity' => 1]], [], ['customer_id' => null]);
            $this->fail();
        } catch (SaleException $e) {
            $this->assertStringContainsString('crédit', $e->getMessage());
        }

        $this->expectException(SaleException::class);
        $this->sell([['product_unit_id' => $this->waterPack->id, 'quantity' => 1]], [['payment_account_id' => $this->cash->id, 'amount' => 5000]]);
    }

    public function test_customer_settlement_pays_oldest_sales_first(): void
    {
        $first = $this->sell([['product_unit_id' => $this->crate->id, 'quantity' => 1]], [], ['occurred_at' => now()->subDays(3)]);
        $second = $this->sell([['product_unit_id' => $this->crate->id, 'quantity' => 1]], [['payment_account_id' => $this->cash->id, 'amount' => 2500]]);
        $this->assertSame(12500, $this->customer->balance());

        app(PaymentService::class)->recordCustomerPayment($this->customer, $this->momo, 9000);

        $this->assertSame(0, $first->fresh()->balance());
        $this->assertSame(3500, $second->fresh()->balance());
        $this->assertSame(3500, $this->customer->balance());
        $this->assertSame(9000, $this->momo->balance());

        $this->expectException(SaleException::class);
        app(PaymentService::class)->recordCustomerPayment($this->customer, $this->cash, 4000);
    }

    public function test_overdue_credit_is_detected_after_fourteen_days(): void
    {
        $this->sell([['product_unit_id' => $this->crate->id, 'quantity' => 1]], [], ['occurred_at' => now()->subDays(15)]);
        $this->sell([['product_unit_id' => $this->bottle->id, 'quantity' => 2]]);

        $this->assertSame(8800, $this->customer->balance());
        $this->assertSame(7500, $this->customer->overdueBalance());

        app(PaymentService::class)->recordCustomerPayment($this->customer, $this->cash, 1000);
        $row = Customer::withBalances()->findOrFail($this->customer->id);
        $this->assertSame(7800, (int) $row->balance_due);
        $this->assertSame(6500, (int) $row->overdue_due);
    }

    public function test_customer_specific_credit_delay_is_used(): void
    {
        $this->customer->update(['credit_days' => 7]);
        $sale = $this->sell([['product_unit_id' => $this->bottle->id, 'quantity' => 1]]);

        $this->assertSame(today()->addDays(7)->toDateString(), $sale->due_date->toDateString());
    }

    public function test_cancelling_a_sale_reverses_stock_empties_and_payments_but_keeps_history(): void
    {
        $sale = $this->sell(
            [['product_unit_id' => $this->crate->id, 'quantity' => 2]],
            [['payment_account_id' => $this->cash->id, 'amount' => 10000]],
        );

        $this->sales->cancel($sale, 'Erreur de saisie');
        $sale->refresh();

        $this->assertTrue($sale->isCancelled());
        $this->assertSame(0, $sale->balance());
        $this->assertSame(240, app(StockService::class)->available($this->depot, $this->crate->product));
        $this->assertSame(0, app(PackagingService::class)->available($this->depot, $this->emptyBottle));
        $this->assertSame(0, $this->cash->balance());
        $this->assertSame(0, $this->customer->balance());
        $this->assertSame(2, AccountTransaction::count(), 'encaissement + contrepassation');
        $this->assertSame(1, StockMovement::where('type', StockMovementType::SaleReturn)->count());
        $this->assertSame(1, Sale::count());

        $this->expectException(SaleException::class);
        $this->sales->cancel($sale, 'Encore');
    }

    public function test_sale_with_later_settlement_cannot_be_cancelled_directly(): void
    {
        $sale = $this->sell([['product_unit_id' => $this->crate->id, 'quantity' => 1]]);
        $settlement = app(PaymentService::class)->recordCustomerPayment($this->customer, $this->cash, 5000);

        try {
            $this->sales->cancel($sale, 'Retour');
            $this->fail();
        } catch (SaleException $e) {
            $this->assertStringContainsString($settlement->number, $e->getMessage());
        }

        app(PaymentService::class)->cancel($settlement, 'Erreur');
        $this->sales->cancel($sale, 'Retour');
        $this->assertSame(0, $this->cash->balance());
    }

    public function test_back_dated_sale_keeps_real_date(): void
    {
        $sale = $this->sell([['product_unit_id' => $this->bottle->id, 'quantity' => 1]], [['payment_account_id' => $this->cash->id, 'amount' => 650]], ['occurred_at' => now()->subDay()->setTime(18, 30)]);

        $this->assertSame(now()->subDay()->toDateString(), $sale->occurred_at->toDateString());
        $this->assertSame(now()->subDay()->toDateString(), StockMovement::where('source_id', $sale->id)->first()->occurred_at->toDateString());
        $this->assertSame('VTE-000001', $sale->number);
    }

    public function test_new_tenant_gets_default_payment_accounts_and_sales_are_isolated(): void
    {
        $this->sell([['product_unit_id' => $this->bottle->id, 'quantity' => 1]]);
        $other = $this->makeTenant('Dépôt B');

        $this->inTenant($other, function () {
            $this->assertSame(['cash', 'mtn_momo', 'orange_money'], PaymentAccount::orderBy('id')->pluck('kind')->all());
            $this->assertSame(0, Sale::count());
            $this->assertSame(0, Customer::count());
            $this->assertSame(0, Payment::count());
        });
    }
}
