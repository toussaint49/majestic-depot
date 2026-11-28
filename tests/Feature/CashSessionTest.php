<?php

namespace Tests\Feature;

use App\Exceptions\CashSessionException;
use App\Models\AccountTransaction;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Depot;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\AccountLedger;
use App\Services\CashSessionService;
use App\Services\PaymentService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Scénario de recette 9 : clôture avec écart, validation et verrouillage. */
class CashSessionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Depot $depot;

    private ProductUnit $pack;

    private PaymentAccount $cash;

    private PaymentAccount $momo;

    private CashSessionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Dépôt A');
        $this->actingAs($this->ownerOf($this->tenant));
        app(CurrentTenant::class)->set($this->tenant);
        $this->depot = Depot::firstOrFail();
        $this->service = app(CashSessionService::class);

        $water = Product::create(['name' => 'Eau 1,5L', 'reference' => 'EAU']);
        $this->pack = $water->units()->create(['name' => 'Pack', 'factor' => 6, 'price' => 2500]);
        app(StockService::class)->receive($this->depot, $water, 600, 300);

        $this->cash = PaymentAccount::where('kind', 'cash')->firstOrFail();
        $this->momo = PaymentAccount::where('kind', 'mtn_momo')->firstOrFail();
    }

    private function sell(int $packs, array $payments, ?Customer $customer = null): Sale
    {
        return app(SaleService::class)->create([
            'depot_id' => $this->depot->id,
            'customer_id' => $customer?->id,
            'lines' => [['product_unit_id' => $this->pack->id, 'quantity' => $packs]],
            'payments' => $payments,
        ]);
    }

    public function test_payment_requires_an_open_cash_session_but_credit_sale_does_not(): void
    {
        try {
            $this->sell(1, [['payment_account_id' => $this->cash->id, 'amount' => 2500]]);
            $this->fail('Encaissement sans caisse ouverte accepté.');
        } catch (CashSessionException $e) {
            $this->assertStringContainsString('Ouvrez la caisse', $e->getMessage());
        }
        $this->assertSame(0, Sale::count());

        $sale = $this->sell(1, [], Customer::create(['name' => 'Client']));
        $this->assertSame(2500, $sale->balance());
    }

    public function test_session_can_be_optional_per_tenant(): void
    {
        $this->tenant->update(['require_cash_session' => false]);
        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->sell(1, [['payment_account_id' => $this->cash->id, 'amount' => 2500]]);
        $this->assertNull(AccountTransaction::first()->cash_session_id);
    }

    public function test_only_one_open_session_per_depot(): void
    {
        $this->service->open($this->depot, 0);

        $this->expectException(CashSessionException::class);
        $this->service->open($this->depot, 0);
    }

    public function test_opening_float_different_from_expected_needs_a_reason_and_is_recorded(): void
    {
        try {
            $this->service->open($this->depot, 10000);
            $this->fail();
        } catch (CashSessionException $e) {
            $this->assertStringContainsString('motif', $e->getMessage());
        }

        $this->service->open($this->depot, 10000, 'Fond de caisse initial');
        $this->assertSame(10000, app(AccountLedger::class)->balance($this->cash));
    }

    public function test_full_day_close_with_difference_validation_and_lock(): void
    {
        $session = $this->service->open($this->depot, 10000, 'Fond initial');

        $this->sell(4, [['payment_account_id' => $this->cash->id, 'amount' => 6000], ['payment_account_id' => $this->momo->id, 'amount' => 4000]]);
        $this->sell(2, [['payment_account_id' => $this->cash->id, 'amount' => 5000]]);
        $this->service->withdraw($session, $this->cash, 3000, 'Achat de sachets');
        $this->service->deposit($session, $this->cash, 1000, 'Monnaie apportée par le gérant');

        $this->assertSame([$this->cash->id => 19000, $this->momo->id => 4000], $this->service->expected($session));

        // Il manque 500 FCFA dans le tiroir ; le relevé MoMo est conforme.
        $this->service->close($session, [$this->cash->id => 18500, $this->momo->id => 4000], 'Billet de 500 introuvable');
        $session->refresh();
        $this->assertSame(CashSession::CLOSED, $session->status);
        $this->assertSame(-500, $session->counts()->where('payment_account_id', $this->cash->id)->value('difference'));

        // Caisse fermée : plus d'encaissement possible avant réouverture.
        try {
            $this->sell(1, [['payment_account_id' => $this->cash->id, 'amount' => 2500]]);
            $this->fail();
        } catch (CashSessionException) {
        }

        // Pas de nouvelle caisse tant que la clôture n'est pas validée.
        try {
            $this->service->open($this->depot, 18500);
            $this->fail();
        } catch (CashSessionException $e) {
            $this->assertStringContainsString('validée', $e->getMessage());
        }

        $this->service->validate($session);
        $this->assertTrue($session->fresh()->isValidated());
        $this->assertSame(18500, app(AccountLedger::class)->balance($this->cash), 'le grand livre égale l\'argent compté');
        $this->assertSame(4000, app(AccountLedger::class)->balance($this->momo));
        $this->assertSame(18500, $this->service->suggestedFloat());

        // Session validée : non modifiable.
        foreach ([fn () => $this->service->close($session->fresh(), [$this->cash->id => 0]), fn () => $this->service->validate($session->fresh()), fn () => $this->service->deposit($session->fresh(), $this->cash, 100, 'x')] as $attempt) {
            try {
                $attempt();
                $this->fail('Session validée modifiée.');
            } catch (CashSessionException) {
            }
        }

        // La journée suivante repart du montant compté, sans écart.
        $next = $this->service->open($this->depot, 18500);
        $this->assertSame(18500, $this->service->expected($next)[$this->cash->id]);
    }

    public function test_close_requires_a_count_for_every_account_used(): void
    {
        $session = $this->service->open($this->depot, 0);
        $this->sell(1, [['payment_account_id' => $this->momo->id, 'amount' => 2500]]);

        $this->expectException(CashSessionException::class);
        $this->service->close($session, [$this->cash->id => 0]);
    }

    public function test_withdrawal_cannot_exceed_expected_cash(): void
    {
        $session = $this->service->open($this->depot, 2000, 'Fond');

        $this->expectException(CashSessionException::class);
        $this->service->withdraw($session, $this->cash, 2500, 'Trop');
    }

    public function test_settlements_and_cancellations_go_to_the_open_session(): void
    {
        $customer = Customer::create(['name' => 'Bar La Joie']);
        $this->sell(2, [], $customer);
        $session = $this->service->open($this->depot, 0);

        $payment = app(PaymentService::class)->recordCustomerPayment($customer, $this->momo, 5000);
        $this->assertSame($session->id, AccountTransaction::where('source_id', $payment->id)->value('cash_session_id'));

        app(PaymentService::class)->cancel($payment, 'Erreur');
        $this->assertSame(0, $this->service->expected($session)[$this->momo->id]);
    }
}
