<?php

namespace Tests\Feature;

use App\Exceptions\CashSessionException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PurchaseException;
use App\Models\Depot;
use App\Models\PackagingType;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\CashSessionService;
use App\Services\PackagingService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Depot $depot;

    private Product $beer;

    private ProductUnit $crate;

    private PackagingType $emptyBottle;

    private Supplier $supplier;

    private PaymentAccount $cash;

    private PurchaseService $purchases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Dépôt A');
        $this->actingAs($this->ownerOf($this->tenant));
        app(CurrentTenant::class)->set($this->tenant);
        $this->depot = Depot::firstOrFail();
        $this->purchases = app(PurchaseService::class);

        $this->emptyBottle = PackagingType::create(['name' => 'Bouteille vide 65cl', 'code' => 'BV65']);
        $this->beer = Product::create(['name' => 'Bière blonde 65cl', 'reference' => 'BB65']);
        $this->beer->units()->create(['name' => 'Bouteille', 'factor' => 1, 'price' => 650]);
        $this->crate = $this->beer->units()->create(['name' => 'Casier', 'factor' => 12, 'price' => 7500]);
        $this->crate->packagings()->create(['packaging_type_id' => $this->emptyBottle->id, 'quantity' => 12]);
        $this->supplier = Supplier::create(['name' => 'Brasserie du Littoral']);
        $this->cash = PaymentAccount::where('kind', 'cash')->firstOrFail();
    }

    private function level(): StockLevel
    {
        return StockLevel::where('depot_id', $this->depot->id)->where('product_id', $this->beer->id)->firstOrFail();
    }

    private function buy(int $crates, int $price, array $extra = []): Purchase
    {
        return $this->purchases->create($extra + [
            'depot_id' => $this->depot->id,
            'supplier_id' => $this->supplier->id,
            'lines' => [['product_unit_id' => $this->crate->id, 'quantity' => $crates, 'unit_price' => $price]],
        ]);
    }

    public function test_purchase_receives_stock_and_recomputes_average_cost(): void
    {
        app(StockService::class)->receive($this->depot, $this->beer, 120, 500); // 10 casiers à 500/btl

        $purchase = $this->buy(10, 6600, ['invoice_number' => 'F-001']); // 550/btl

        $this->assertSame('ACH-000001', $purchase->number);
        $this->assertSame(66000, $purchase->total);
        $this->assertSame(240, $this->level()->quantity);
        $this->assertEqualsWithDelta(525, (float) $this->level()->avg_cost, 0.0001);
        $this->assertSame(66000, $purchase->balance());
        $this->assertSame(66000, $this->supplier->balance());
        $this->assertSame(66000, (int) Supplier::withBalance()->find($this->supplier->id)->balance_due);
    }

    public function test_same_supplier_invoice_cannot_be_recorded_twice(): void
    {
        $this->buy(1, 6600, ['invoice_number' => 'F-001']);

        $this->expectException(PurchaseException::class);
        $this->buy(1, 6600, ['invoice_number' => 'F-001']);
    }

    public function test_paying_at_purchase_takes_money_from_the_open_cash_session(): void
    {
        $session = $this->openCash();
        app(CashSessionService::class)->deposit($session, $this->cash, 50000, 'Apport du propriétaire');

        $purchase = $this->buy(10, 6600, ['payments' => [['payment_account_id' => $this->cash->id, 'amount' => 40000]]]);

        $this->assertSame(26000, $purchase->balance());
        $this->assertSame(10000, app(CashSessionService::class)->expected($session)[$this->cash->id]);
    }

    public function test_payment_cannot_exceed_cash_available_or_balance(): void
    {
        $session = $this->openCash();
        app(CashSessionService::class)->deposit($session, $this->cash, 10000, 'Apport');
        $purchase = $this->buy(1, 6600);

        try {
            $this->purchases->pay($purchase, $this->cash, 7000);
            $this->fail('Dépassement du reste à payer accepté');
        } catch (PurchaseException $e) {
            $this->assertStringContainsString('6 600', $e->getMessage());
        }

        $big = $this->buy(5, 6600);
        $this->expectException(PurchaseException::class);
        $this->purchases->pay($big, $this->cash, 20000); // seulement 10 000 en caisse
    }

    public function test_payment_requires_an_open_cash_session(): void
    {
        $purchase = $this->buy(1, 6600);

        $this->expectException(CashSessionException::class);
        $this->purchases->pay($purchase, $this->cash, 1000);
    }

    public function test_empties_returned_to_supplier_leave_the_packaging_stock(): void
    {
        $packaging = app(PackagingService::class);
        $packaging->receive($this->depot, $this->emptyBottle, 200);

        $purchase = $this->buy(10, 6600, ['empties_returned' => [['packaging_type_id' => $this->emptyBottle->id, 'quantity' => 120]]]);
        $this->assertSame(80, $packaging->available($this->depot, $this->emptyBottle));

        $this->purchases->cancel($purchase, 'Erreur de saisie');
        $this->assertSame(200, $packaging->available($this->depot, $this->emptyBottle));
        $this->assertSame(0, $this->level()->quantity);
        $this->assertSame(0, $this->supplier->balance());
    }

    public function test_cancel_is_refused_when_paid_or_already_sold(): void
    {
        $session = $this->openCash();
        app(CashSessionService::class)->deposit($session, $this->cash, 10000, 'Apport');
        $paid = $this->buy(1, 6600, ['payments' => [['payment_account_id' => $this->cash->id, 'amount' => 6600]]]);

        try {
            $this->purchases->cancel($paid, 'Erreur');
            $this->fail('Annulation d\'un achat payé acceptée');
        } catch (PurchaseException) {
        }

        $payment = $paid->payments()->firstOrFail();
        $this->purchases->cancelPayment($payment, 'Erreur');
        $this->assertSame(10000, app(CashSessionService::class)->expected($session)[$this->cash->id]);

        app(PackagingService::class)->receive($this->depot, $this->emptyBottle, 12);
        app(SaleService::class)->create([
            'depot_id' => $this->depot->id,
            'empties_returned' => true,
            'lines' => [['product_unit_id' => $this->crate->id, 'quantity' => 1]],
            'payments' => [['payment_account_id' => $this->cash->id, 'amount' => 7500]],
        ]);

        $this->expectException(InsufficientStockException::class);
        $this->purchases->cancel($paid, 'Erreur');
    }

    public function test_purchases_are_isolated_per_tenant(): void
    {
        $this->buy(1, 6600);
        $other = $this->makeTenant('Dépôt B');

        $this->assertSame(0, $this->inTenant($other, fn () => Purchase::count()));
        $this->assertSame(0, $this->inTenant($other, fn () => Supplier::count()));
    }
}
