<?php

namespace Tests\Feature;

use App\Filament\Resources\CashSessions\Pages\OpenCashSession;
use App\Filament\Resources\CashSessions\Pages\ViewCashSession;
use App\Models\CashSession;
use App\Models\Depot;
use App\Models\PaymentAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\TenantProvisioner;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CashSessionPanelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Dépôt A');
        $this->cashier = User::factory()->create();
        app(TenantProvisioner::class)->addMember($this->tenant, $this->cashier, Roles::CAISSIER);
        $this->enter($this->cashier);
    }

    private function enter(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->tenant);
        app(CurrentTenant::class)->set($this->tenant);
    }

    public function test_cashier_opens_and_closes_but_manager_validates(): void
    {
        Livewire::test(OpenCashSession::class)
            ->fillForm(['depot_id' => Depot::first()->id, 'opening_float' => 15000, 'reason' => 'Premier fond de caisse'])
            ->call('create')
            ->assertHasNoFormErrors();

        $session = CashSession::firstOrFail();
        $this->assertSame(15000, $session->opening_float);
        $cash = PaymentAccount::where('kind', 'cash')->value('id');

        $this->get("/app/{$this->tenant->slug}/cash-sessions/{$session->id}")->assertOk()->assertSee('15 000 FCFA');

        Livewire::test(ViewCashSession::class, ['record' => $session->getRouteKey()])
            ->assertActionHidden('withdraw')
            ->assertActionHidden('validate')
            ->callAction('close', data: ['counted' => [$cash => 14000], 'notes' => 'Manque 1 000'])
            ->assertHasNoActionErrors();

        $this->assertSame(CashSession::CLOSED, $session->fresh()->status);

        Livewire::test(ViewCashSession::class, ['record' => $session->getRouteKey()])->assertActionHidden('validate');

        $this->enter($this->ownerOf($this->tenant));
        Livewire::test(ViewCashSession::class, ['record' => $session->getRouteKey()])
            ->callAction('validate')
            ->assertNotified('Clôture validée');

        $this->assertTrue($session->fresh()->isValidated());
        $this->assertSame(14000, app(CashSessionService::class)->suggestedFloat());
        $this->get("/app/{$this->tenant->slug}/cash-sessions")->assertOk()->assertSee('-1 000 FCFA');
    }

    public function test_opening_with_a_different_float_requires_a_reason_in_the_form(): void
    {
        Livewire::test(OpenCashSession::class)
            ->fillForm(['depot_id' => Depot::first()->id, 'opening_float' => 5000])
            ->call('create')
            ->assertHasFormErrors(['reason' => 'required']);
    }

    public function test_opening_twice_is_refused_with_a_message(): void
    {
        app(CashSessionService::class)->open(Depot::first(), 0);

        Livewire::test(OpenCashSession::class)
            ->fillForm(['depot_id' => Depot::first()->id, 'opening_float' => 0])
            ->call('create')
            ->assertNotified('Ouverture refusée');

        $this->assertSame(1, CashSession::count());
    }
}
