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
php artisan db:seed --class=DemoCatalogSeeder   # catalogue de démonstration (local)
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

## Règles de stock

- Les quantités sont stockées en **unité de base** (ex. bouteille) ; chaque produit a des
  conditionnements (Casier ×12, Pack ×6…) avec leur prix de vente.
- Tout mouvement passe par `StockService` : solde verrouillé, contrôle, mouvement immuable,
  mise à jour du solde et du CUMP, dans une seule transaction. **Stock négatif interdit.**
- CUMP : recalculé aux entrées valorisées (stock initial, achat, retour de vente, transfert reçu) ;
  les sorties, pertes, ajustements et écarts d'inventaire passent au CUMP courant sans le modifier.
- Inventaire : comptage en brouillon (magasinier), validation (gérant/propriétaire) qui fige le
  stock théorique et passe les écarts en mouvements.
- Date réelle (`occurred_at`) distincte de la date de saisie : permet la saisie différée des fiches papier.

## Règles des emballages vides

- Sous-système indépendant du stock de boissons : types d'emballages (bouteille vide 65cl, casier 12…)
  et stock de vides par dépôt (`PackagingService`), mouvements immuables, stock négatif interdit.
- Chaque conditionnement déclare les vides à échanger pour 1 unité vendue
  (ex. Casier → 12 bouteilles vides + 1 casier ; Bouteille → 1 bouteille vide ; eau PET → rien).
- Règle du pilote : **pas de vides, pas de vente**. Pas de caution, pas de solde d'emballages par client.
  `collectForSale()` vérifie les vides rapportés et les entre en stock ; `reverseSaleExchange()` les rend en cas d'annulation.

## Déploiement

Voir [docs/DEPLOIEMENT_CPANEL.md](docs/DEPLOIEMENT_CPANEL.md).

## Feuille de route MVP

1. ✅ Socle : entreprises, utilisateurs, rôles, audit, dépôts
2. ✅ Catalogue et stock (bouteille/casier, CUMP, inventaires, stock négatif bloqué)
3. ✅ Emballages vides (échange obligatoire à la vente, retours fournisseur)
4. Ventes et clients (espèces, MTN MoMo, Orange Money, crédit 14 jours, ticket PDF, annulation)
5. Caisse et clôture journalière
