<?php

namespace App\Services;

use App\Models\Depot;
use App\Models\PaymentAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Création d'une entreprise prête à l'emploi : rôles, propriétaire, dépôt principal.
 */
class TenantProvisioner
{
    public function __construct(private CurrentTenant $currentTenant) {}

    public static function syncPermissions(): void
    {
        foreach (Roles::allPermissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function create(array $attributes, User $owner, string $depotName = 'Dépôt principal'): Tenant
    {
        return DB::transaction(function () use ($attributes, $owner, $depotName) {
            $attributes['slug'] ??= $this->uniqueSlug($attributes['name']);
            $tenant = Tenant::create($attributes);
            $tenant->users()->attach($owner);

            $this->currentTenant->run($tenant, function (Tenant $tenant) use ($owner, $depotName) {
                $this->syncRoles($tenant);
                $owner->assignRole(Roles::PROPRIETAIRE);
                Depot::create(['name' => $depotName, 'code' => 'DEP01']);
                foreach (PaymentAccount::DEFAULTS as $account) {
                    PaymentAccount::create($account);
                }
            });

            return $tenant;
        });
    }

    /** Crée ou met à jour les rôles standards d'une entreprise. */
    public function syncRoles(Tenant $tenant): void
    {
        static::syncPermissions();

        $this->currentTenant->run($tenant, function (Tenant $tenant) {
            foreach (Roles::matrix() as $roleName => $permissions) {
                $role = Role::findOrCreate($roleName, 'web');
                $role->syncPermissions($permissions);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function addMember(Tenant $tenant, User $user, string $role): void
    {
        $tenant->users()->syncWithoutDetaching([$user->getKey()]);
        $this->currentTenant->run($tenant, fn () => $user->assignRole($role));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'entreprise';
        $slug = $base;
        $i = 2;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
