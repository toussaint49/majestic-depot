<?php

namespace Tests;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function makeTenant(string $name, ?User $owner = null): Tenant
    {
        $owner ??= User::factory()->create();

        return app(TenantProvisioner::class)->create(['name' => $name], $owner);
    }

    protected function inTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(CurrentTenant::class)->run($tenant, $callback);
    }

    protected function ownerOf(Tenant $tenant): User
    {
        return $tenant->users()->firstOrFail();
    }
}
