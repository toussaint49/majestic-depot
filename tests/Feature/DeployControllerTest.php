<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Page de mise à jour de la base sans SSH ni Cron. Base vide au départ (pas de RefreshDatabase). */
class DeployControllerTest extends TestCase
{
    private string $token = 'jeton-de-test-0123456789-abcdefghijklmnop';

    public function test_page_is_disabled_without_token(): void
    {
        config(['app.deploy_token' => '']);
        $this->get('/maintenance/migrate/nimporte')->assertNotFound();

        config(['app.deploy_token' => 'trop-court']);
        $this->get('/maintenance/migrate/trop-court')->assertNotFound();
    }

    public function test_wrong_token_is_refused(): void
    {
        config(['app.deploy_token' => $this->token]);

        $this->get('/maintenance/migrate/mauvais-jeton-0123456789-abcdefghijk')->assertNotFound();
        $this->assertFalse(Schema::hasTable('users'));
    }

    public function test_installs_an_empty_database_then_only_migrates(): void
    {
        config(['app.deploy_token' => $this->token]);
        $this->assertFalse(Schema::hasTable('sessions'));

        $this->get("/maintenance/migrate/{$this->token}")->assertOk()->assertSee('DONE');

        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertSame(1, User::count());
        $this->assertSame(1, Tenant::count());

        $this->get("/maintenance/migrate/{$this->token}")->assertOk()->assertSee('déjà présentes');
        $this->assertSame(1, User::count());

        $this->get('/app/login')->assertOk();
    }
}
