<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Depot;
use App\Models\PackagingType;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PackagingService;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $token;

    private ProductUnit $crate;

    private PackagingType $empty;

    private Supplier $supplier;

    private PaymentAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Dépôt A');
        app(CurrentTenant::class)->set($this->tenant);
        [, $this->token] = ApiToken::issue($this->ownerOf($this->tenant), 'Claude');

        $this->empty = PackagingType::create(['name' => 'Bouteille vide 65cl', 'code' => 'BV65']);
        $beer = Product::create(['name' => 'Bière blonde 65cl', 'reference' => 'BB65']);
        $beer->units()->create(['name' => 'Bouteille', 'factor' => 1, 'price' => 650]);
        $this->crate = $beer->units()->create(['name' => 'Casier', 'factor' => 12, 'price' => 7500]);
        $this->crate->packagings()->create(['packaging_type_id' => $this->empty->id, 'quantity' => 12]);
        $this->supplier = Supplier::create(['name' => 'Brasserie du Littoral']);
        $this->cash = PaymentAccount::where('kind', 'cash')->firstOrFail();
        app(PackagingService::class)->receive(Depot::first(), $this->empty, 100);

        app(CurrentTenant::class)->set(null); // l'API doit trouver l'entreprise seule, par le jeton
    }

    private function api(): static
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json']);
    }

    private function purchase(array $extra = []): array
    {
        return $extra + [
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'F-001',
            'lines' => [['product_unit_id' => $this->crate->id, 'quantity' => 10, 'unit_price' => 6600]],
            'empties_returned' => [['packaging_type_id' => $this->empty->id, 'quantity' => 24]],
        ];
    }

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        $this->getJson('/api/v1/catalogue')->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer mjd_faux'])->getJson('/api/v1/catalogue')->assertUnauthorized();

        app(CurrentTenant::class)->set($this->tenant);
        ApiToken::query()->update(['revoked_at' => now()]);
        $this->api()->getJson('/api/v1/catalogue')->assertUnauthorized();
    }

    public function test_catalogue_lists_units_and_empties(): void
    {
        $this->api()->getJson('/api/v1/catalogue')->assertOk()
            ->assertJsonPath('products.0.name', 'Bière blonde 65cl')
            ->assertJsonPath('products.0.units.1.factor', 12)
            ->assertJsonPath('products.0.units.1.empties.0.quantity', 12)
            ->assertJsonPath('suppliers.0.name', 'Brasserie du Littoral');
    }

    public function test_purchase_is_recorded_once_even_if_sent_twice(): void
    {
        $this->api()->withHeader('Idempotency-Key', 'facture-F-001')->postJson('/api/v1/achats', $this->purchase())
            ->assertCreated()->assertJsonPath('total', 66000);
        $this->api()->withHeader('Idempotency-Key', 'facture-F-001')->postJson('/api/v1/achats', $this->purchase())
            ->assertCreated()->assertHeader('Idempotent-Replay', 'true');

        app(CurrentTenant::class)->set($this->tenant);
        $this->assertSame(1, Purchase::count());
        $this->assertStringContainsString('[saisi par Claude]', Purchase::first()->notes);

        // Autre clé, même facture : refusé par la règle « facture déjà enregistrée ».
        $this->api()->withHeader('Idempotency-Key', 'autre')->postJson('/api/v1/achats', $this->purchase())
            ->assertStatus(422)->assertJsonPath('error', fn ($m) => str_contains($m, 'déjà enregistrée'));
    }

    public function test_writes_require_an_idempotency_key(): void
    {
        $this->api()->postJson('/api/v1/achats', $this->purchase())->assertStatus(400);
    }

    public function test_daily_sheet_flow_open_sell_close_and_day_summary(): void
    {
        $this->api()->withHeader('Idempotency-Key', 'f1')->postJson('/api/v1/achats', $this->purchase())->assertCreated();
        $this->api()->withHeader('Idempotency-Key', 'o1')->postJson('/api/v1/caisse/ouvrir', ['opening_float' => 0])->assertCreated();

        $customer = $this->api()->withHeader('Idempotency-Key', 'c1')->postJson('/api/v1/clients', ['name' => 'Bar La Joie'])->assertCreated()->json('id');

        $this->api()->withHeader('Idempotency-Key', 'v1')->postJson('/api/v1/ventes', [
            'empties_returned' => true,
            'lines' => [['product_unit_id' => $this->crate->id, 'quantity' => 2]],
            'payments' => [['payment_account_id' => $this->cash->id, 'amount' => 15000]],
        ])->assertCreated()->assertJsonPath('total', 15000);

        $this->api()->withHeader('Idempotency-Key', 'v2')->postJson('/api/v1/ventes', [
            'customer_id' => $customer,
            'empties_returned' => true,
            'lines' => [['product_unit_id' => $this->crate->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('credit_amount', 7500);

        // Sans vides : refusé, et la clé n'est pas mémorisée.
        $this->api()->withHeader('Idempotency-Key', 'v3')->postJson('/api/v1/ventes', [
            'empties_returned' => false,
            'lines' => [['product_unit_id' => $this->crate->id, 'quantity' => 1]],
            'payments' => [['payment_account_id' => $this->cash->id, 'amount' => 7500]],
        ])->assertStatus(422);

        $day = $this->api()->getJson('/api/v1/journee?date='.now()->toDateString())->assertOk();
        $day->assertJsonPath('sales.count', 2)->assertJsonPath('sales.total', 22500)->assertJsonPath('sales.credit', 7500)
            ->assertJsonPath('products.0.opening', 0)->assertJsonPath('products.0.closing', 84)
            ->assertJsonPath('products.0.movements.purchase_in', 120)->assertJsonPath('products.0.movements.sale_out', -36);
        $this->assertSame(15000, $day->json('payments_by_account.'.$this->cash->id));

        $this->api()->getJson('/api/v1/stock')->assertOk()->assertJsonPath('products.0.quantity', 84);

        $this->api()->withHeader('Idempotency-Key', 'cl1')->postJson('/api/v1/caisse/cloturer', [
            'counted' => collect(PaymentAccount::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->pluck('id'))
                ->mapWithKeys(fn ($id) => [$id => $id === $this->cash->id ? 15000 : 0])->all(),
        ])->assertOk()->assertJsonPath('status', 'closed');

        app(CurrentTenant::class)->set($this->tenant);
        $this->assertSame(2, Sale::count());
    }

    public function test_unknown_product_returns_a_readable_404(): void
    {
        $this->api()->withHeader('Idempotency-Key', 'x')->postJson('/api/v1/achats', $this->purchase(['lines' => [['product_unit_id' => 9999, 'quantity' => 1, 'unit_price' => 1]]]))
            ->assertNotFound()->assertJsonStructure(['error']);
    }

    public function test_token_permissions_follow_its_user_role(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        $cashier = User::factory()->create();
        $this->tenant->users()->attach($cashier, ['is_active' => true]);
        $cashier->assignRole(Roles::CAISSIER);
        [, $plain] = ApiToken::issue($cashier, 'caisse');
        app(CurrentTenant::class)->set(null);

        $this->withHeaders(['Authorization' => "Bearer $plain", 'Idempotency-Key' => 'k'])
            ->postJson('/api/v1/achats', $this->purchase())->assertForbidden();
    }

    public function test_a_token_only_sees_its_own_company(): void
    {
        $other = $this->makeTenant('Dépôt B');
        app(CurrentTenant::class)->run($other, fn () => Supplier::create(['name' => 'Fournisseur B']));
        app(CurrentTenant::class)->set(null);

        $names = collect($this->api()->getJson('/api/v1/catalogue')->json('suppliers'))->pluck('name');
        $this->assertEquals(['Brasserie du Littoral'], $names->all());
    }
}
