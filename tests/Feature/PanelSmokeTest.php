<?php

namespace Tests\Feature;

use App\Filament\Resources\Depots\Pages\CreateDepot;
use App\Models\Depot;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_page_opens_for_owner(): void
    {
        $a = $this->makeTenant('Dépôt A');

        $this->actingAs($this->ownerOf($a))->get("/app/{$a->slug}/audit-logs")->assertOk();
    }

    public function test_depot_created_from_panel_belongs_to_current_tenant(): void
    {
        $a = $this->makeTenant('Dépôt A');
        $this->makeTenant('Dépôt B');
        $this->actingAs($this->ownerOf($a));
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($a);
        $this->inTenant($a, function () {
            Livewire::test(CreateDepot::class)
                ->fillForm(['name' => 'Annexe Bonabéri', 'code' => 'BNB'])
                ->call('create')
                ->assertHasNoFormErrors();

            $this->assertTrue(Depot::where('code', 'BNB')->exists());
        });

        $this->assertDatabaseHas('depots', ['code' => 'BNB', 'tenant_id' => $a->id]);
    }

    public function test_depot_code_must_be_unique_inside_a_tenant(): void
    {
        $a = $this->makeTenant('Dépôt A');
        $this->actingAs($this->ownerOf($a));
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($a);

        $this->inTenant($a, fn () => Livewire::test(CreateDepot::class)
            ->fillForm(['name' => 'Doublon', 'code' => 'DEP01'])
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']));
    }
}
