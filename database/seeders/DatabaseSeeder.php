<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Database\Seeder;

/**
 * Données de démarrage : permissions, un super administrateur et l'entreprise pilote.
 * Identifiants pris dans .env (SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD).
 */
class DatabaseSeeder extends Seeder
{
    public function run(TenantProvisioner $provisioner): void
    {
        TenantProvisioner::syncPermissions();

        $password = env('SEED_ADMIN_PASSWORD') ?: (app()->isProduction() ? null : 'password');

        if ($password === null) {
            throw new \RuntimeException('Définissez SEED_ADMIN_PASSWORD dans .env avant de lancer le seeder en production.');
        }

        $owner = User::firstOrCreate(
            ['email' => env('SEED_ADMIN_EMAIL', 'admin@majestic.test')],
            ['name' => env('SEED_ADMIN_NAME', 'Propriétaire'), 'password' => $password],
        );
        $owner->forceFill(['is_super_admin' => true])->save();

        if ($owner->tenants()->doesntExist()) {
            $provisioner->create(['name' => 'Majestic Dépôt', 'slug' => 'majestic'], $owner);
        }
    }
}
