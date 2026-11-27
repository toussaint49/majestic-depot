<?php

namespace App\Support;

/**
 * Catalogue des rôles et permissions d'une entreprise.
 *
 * Les permissions sont globales ("module.action") ; les rôles sont créés pour
 * chaque entreprise (équipes spatie/laravel-permission, clé tenant_id), ce qui
 * permet plus tard à chaque entreprise d'ajuster ses propres rôles.
 */
final class Roles
{
    public const PROPRIETAIRE = 'proprietaire';
    public const ADMINISTRATEUR = 'administrateur';
    public const GERANT = 'gerant';
    public const CAISSIER = 'caissier';
    public const MAGASINIER = 'magasinier';
    public const COMPTABLE = 'comptable';

    /** Libellés affichés. */
    public const LABELS = [
        self::PROPRIETAIRE => 'Propriétaire',
        self::ADMINISTRATEUR => 'Administrateur',
        self::GERANT => 'Gérant',
        self::CAISSIER => 'Caissier',
        self::MAGASINIER => 'Magasinier',
        self::COMPTABLE => 'Comptable',
    ];

    /** Modules et actions possibles. */
    public const MODULES = [
        'parametres' => ['view', 'update'],
        'utilisateurs' => ['view', 'create', 'update'],
        'depots' => ['view', 'create', 'update'],
        'audit' => ['view'],
        'produits' => ['view', 'create', 'update'],
        'stocks' => ['view', 'create', 'validate'],
        'inventaires' => ['view', 'create', 'validate'],
        'emballages' => ['view', 'create', 'validate'],
        'clients' => ['view', 'create', 'update'],
        'ventes' => ['view', 'create', 'cancel', 'export'],
        'paiements' => ['view', 'create', 'cancel'],
        'fournisseurs' => ['view', 'create', 'update'],
        'achats' => ['view', 'create', 'validate', 'cancel'],
        'depenses' => ['view', 'create', 'cancel'],
        'tresorerie' => ['view'],
        'caisses' => ['view', 'open', 'close', 'validate'],
        'rapports' => ['view', 'export'],
    ];

    /** @return list<string> */
    public static function allPermissions(): array
    {
        $permissions = [];
        foreach (self::MODULES as $module => $actions) {
            foreach ($actions as $action) {
                $permissions[] = "$module.$action";
            }
        }

        return $permissions;
    }

    /** @return array<string, list<string>> rôle => permissions */
    public static function matrix(): array
    {
        $all = self::allPermissions();
        $only = fn (array $patterns) => array_values(array_filter($all, function ($p) use ($patterns) {
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $p)) {
                    return true;
                }
            }

            return false;
        }));

        return [
            self::PROPRIETAIRE => $all,
            self::ADMINISTRATEUR => $only(['parametres.*', 'utilisateurs.*', 'depots.*', 'audit.*', 'produits.*', '*.view']),
            self::GERANT => array_values(array_diff($all, ['parametres.update', 'utilisateurs.create', 'utilisateurs.update'])),
            self::CAISSIER => $only([
                'produits.view', 'stocks.view', 'clients.view', 'clients.create',
                'ventes.view', 'ventes.create', 'paiements.view', 'paiements.create',
                'emballages.view', 'emballages.create', 'depenses.create', 'caisses.view', 'caisses.open', 'caisses.close',
            ]),
            self::MAGASINIER => $only([
                'produits.view', 'stocks.view', 'stocks.create', 'inventaires.view', 'inventaires.create',
                'emballages.view', 'emballages.create', 'achats.view', 'fournisseurs.view', 'depots.view',
            ]),
            self::COMPTABLE => $only([
                '*.view', 'ventes.export', 'rapports.export',
            ]),
        ];
    }
}
