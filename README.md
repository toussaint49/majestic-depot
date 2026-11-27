# Majestic Depot

Plateforme SaaS de gestion de dépôt de boissons (multi-entreprises).
Pilote : dépôt Majestic, Cameroun (FCFA, Africa/Douala).

## Stack

- Laravel 12 (PHP 8.3), monolithe modulaire
- MySQL en production, SQLite en local et pour les tests
- Filament 4 (interface web, utilisable sur téléphone) — panneau `/app/{entreprise}`
- spatie/laravel-permission en mode équipes (rôles par entreprise)

## Démarrer en local

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve        # http://localhost:8000/app  (admin@majestic.test / password)
php artisan test
```

## Principes du socle

- **Isolation des entreprises** : tout modèle métier utilise `BelongsToTenant`. Les lectures sont
  filtrées sur l'entreprise active (`CurrentTenant`), le `tenant_id` est imposé côté serveur
  et ne peut jamais être changé. Sans entreprise active, aucune donnée n'est lue.
- **Rôles** : propriétaire, administrateur, gérant, caissier, magasinier, comptable
  (`app/Support/Roles.php`). Permissions `module.action`, rôles créés par entreprise.
- **Audit** : `Auditable` journalise création, modification et suppression ; le journal est immuable.
- **Pas de suppression** des opérations validées : on annule par contrepassation.

## Déploiement

Voir [docs/DEPLOIEMENT_CPANEL.md](docs/DEPLOIEMENT_CPANEL.md).

## Feuille de route MVP

1. ✅ Socle : entreprises, utilisateurs, rôles, audit, dépôts
2. Catalogue et stock (bouteille/casier, CUMP, inventaires, stock négatif bloqué)
3. Emballages vides (échange obligatoire à la vente, retours fournisseur)
4. Ventes et clients (espèces, MTN MoMo, Orange Money, crédit 14 jours, ticket PDF, annulation)
5. Caisse et clôture journalière
