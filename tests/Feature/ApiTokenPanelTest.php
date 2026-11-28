<?php

namespace Tests\Feature;

use App\Filament\Resources\ApiTokens\Pages\ListApiTokens;
use App\Models\ApiToken;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApiTokenPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_issue_and_revoke_a_token(): void
    {
        $tenant = $this->makeTenant('Dépôt A');
        $this->actingAs($this->ownerOf($tenant));
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tenant);
        app(CurrentTenant::class)->set($tenant);

        Livewire::test(ListApiTokens::class)->callAction('issue', ['name' => 'Claude'])->assertHasNoActionErrors();
        $token = ApiToken::firstOrFail();
        $this->assertSame(64, strlen($token->token_hash));

        Livewire::test(ListApiTokens::class)->callTableAction('revoke', $token)->assertHasNoTableActionErrors();
        $this->assertFalse($token->refresh()->isActive());
    }
}
