<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Depot;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Scénario de recette n°1 du cahier des charges : deux entreprises ne peuvent
 * ni consulter ni modifier les données l'une de l'autre.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_tenant_only_sees_its_own_depots(): void
    {
        $a = $this->makeTenant('Dépôt A');
        $b = $this->makeTenant('Dépôt B');

        $this->inTenant($a, fn () => Depot::create(['name' => 'Annexe A', 'code' => 'A2']));

        $this->assertSame(['DEP01', 'A2'], $this->inTenant($a, fn () => Depot::orderBy('id')->pluck('code')->all()));
        $this->assertSame(['DEP01'], $this->inTenant($b, fn () => Depot::orderBy('id')->pluck('code')->all()));
    }

    public function test_queries_without_active_tenant_return_nothing(): void
    {
        $this->makeTenant('Dépôt A');

        $this->assertSame(0, Depot::count());
    }

    public function test_creating_without_active_tenant_is_refused(): void
    {
        $this->expectException(LogicException::class);

        Depot::create(['name' => 'Orphelin', 'code' => 'X']);
    }

    public function test_tenant_id_sent_by_client_is_ignored(): void
    {
        $a = $this->makeTenant('Dépôt A');
        $b = $this->makeTenant('Dépôt B');

        $depot = $this->inTenant($a, function () use ($b) {
            $depot = new Depot(['name' => 'Piège', 'code' => 'P1']);
            $depot->tenant_id = $b->id;
            $depot->save();

            return $depot;
        });

        $this->assertSame($a->id, $depot->tenant_id);
    }

    public function test_a_record_cannot_be_moved_to_another_tenant(): void
    {
        $a = $this->makeTenant('Dépôt A');
        $b = $this->makeTenant('Dépôt B');

        $this->expectException(LogicException::class);

        $this->inTenant($a, function () use ($b) {
            $depot = Depot::firstOrFail();
            $depot->tenant_id = $b->id;
            $depot->save();
        });
    }

    public function test_a_record_of_another_tenant_cannot_be_loaded_by_id(): void
    {
        $a = $this->makeTenant('Dépôt A');
        $b = $this->makeTenant('Dépôt B');
        $depotOfA = $this->inTenant($a, fn () => Depot::firstOrFail());

        $this->assertNull($this->inTenant($b, fn () => Depot::find($depotOfA->id)));
    }

    public function test_user_cannot_open_the_panel_of_another_tenant(): void
    {
        $a = $this->makeTenant('Dépôt A');
        $b = $this->makeTenant('Dépôt B');

        $this->actingAs($this->ownerOf($a))
            ->get("/app/{$b->slug}")
            ->assertNotFound();
    }

    public function test_user_cannot_open_a_depot_of_another_tenant_through_its_own_panel(): void
    {
        $a = $this->makeTenant('Dépôt A');
        $b = $this->makeTenant('Dépôt B');
        $depotOfB = $this->inTenant($b, fn () => Depot::firstOrFail());

        $this->actingAs($this->ownerOf($a))
            ->get("/app/{$a->slug}/depots/{$depotOfB->id}/edit")
            ->assertNotFound();
    }

    public function test_owner_can_open_own_panel_and_depots(): void
    {
        $a = $this->makeTenant('Dépôt A');

        $this->actingAs($this->ownerOf($a))->get("/app/{$a->slug}")->assertOk();
        $this->actingAs($this->ownerOf($a))->get("/app/{$a->slug}/depots")->assertOk()->assertSee('DEP01');
    }

    public function test_roles_are_scoped_per_tenant(): void
    {
        $a = $this->makeTenant('Dépôt A');
        $b = $this->makeTenant('Dépôt B');
        $user = $this->ownerOf($a);
        app(TenantProvisioner::class)->addMember($b, $user, Roles::CAISSIER);

        $this->assertTrue($this->inTenant($a, fn () => $user->fresh()->can('depots.create')));
        $this->assertFalse($this->inTenant($b, fn () => $user->fresh()->can('depots.create')));
        $this->assertTrue($this->inTenant($b, fn () => $user->fresh()->can('ventes.create')));
    }

    public function test_cashier_cannot_create_a_depot(): void
    {
        $a = $this->makeTenant('Dépôt A');
        $cashier = User::factory()->create();
        app(TenantProvisioner::class)->addMember($a, $cashier, Roles::CAISSIER);

        $this->actingAs($cashier)->get("/app/{$a->slug}/depots/create")->assertForbidden();
    }

    public function test_inactive_membership_blocks_access(): void
    {
        $a = $this->makeTenant('Dépôt A');
        $user = User::factory()->create();
        app(TenantProvisioner::class)->addMember($a, $user, Roles::GERANT);
        $a->users()->updateExistingPivot($user->id, ['is_active' => false]);

        $this->actingAs($user)->get("/app/{$a->slug}")->assertNotFound();
    }

    public function test_changes_are_audited_per_tenant_and_audit_is_immutable(): void
    {
        $a = $this->makeTenant('Dépôt A');
        $b = $this->makeTenant('Dépôt B');
        $owner = $this->ownerOf($a);
        $this->actingAs($owner);

        $this->inTenant($a, function () {
            $depot = Depot::firstOrFail();
            $depot->update(['name' => 'Dépôt central']);
        });

        $log = $this->inTenant($a, fn () => AuditLog::where('action', 'updated')->where('auditable_type', (new Depot)->getMorphClass())->latest('id')->firstOrFail());
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame('Dépôt central', $log->new_values['name']);
        $this->assertSame('Dépôt principal', $log->old_values['name']);

        $this->assertSame(0, $this->inTenant($b, fn () => AuditLog::where('auditable_type', (new Depot)->getMorphClass())->where('action', 'updated')->count()));

        $this->expectException(LogicException::class);
        $log->update(['action' => 'falsifié']);
    }
}
