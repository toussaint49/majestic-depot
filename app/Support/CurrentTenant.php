<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Entreprise active de la requête courante.
 *
 * Elle est toujours déterminée côté serveur (panneau Filament, middleware API
 * après vérification de l'appartenance), jamais à partir d'un tenant_id envoyé
 * par le client.
 */
class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant?->getKey());
        }
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Exécute un traitement dans le contexte d'une entreprise donnée
     * (tâches Cron, seeders, tests), puis restaure le contexte précédent.
     */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback($tenant);
        } finally {
            $this->set($previous);
        }
    }
}
