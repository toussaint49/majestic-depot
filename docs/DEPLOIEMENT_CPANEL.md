# Déploiement sur cPanel (GreenGeeks, sans SSH)

Cible : `manager.protaiin.com`, PHP 8.3, MySQL.

## 1. Préparer l'archive (sur un poste avec PHP et Composer)

```bash
scripts/build-release.sh
```

L'archive `build/majestic-depot-<date>.zip` contient le code et le dossier `vendor/`.

## 2. Envoyer les fichiers

1. Dans le Gestionnaire de fichiers, créer `~/majestic-depot/` **hors** de `public_html`.
2. Y envoyer l'archive puis l'extraire.
3. Dans cPanel > Domaines, faire pointer `manager.protaiin.com` vers `~/majestic-depot/public`.
   Si cPanel impose un dossier sous `public_html`, copier uniquement le contenu de `public/`
   et adapter les deux chemins de `index.php` vers `~/majestic-depot/`.
4. Créer `~/majestic-depot/.env` à partir de `.env.example` :
   `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://manager.protaiin.com`,
   identifiants MySQL, `SEED_ADMIN_*`, et une `APP_KEY` (générée en local avec `php artisan key:generate --show`).
5. Permissions : `storage/` et `bootstrap/cache/` en écriture (755 ; 775 si nécessaire). `.env` en 640.

## 3. Base de données

Créer la base et l'utilisateur dans « Bases de données MySQL » (mot de passe en lettres et chiffres
uniquement), associer l'utilisateur à la base avec **tous les privilèges**.

### Méthode recommandée : page de mise à jour par jeton

1. Ajouter dans `.env` une ligne `DEPLOY_TOKEN=` suivie d'un jeton aléatoire d'au moins 32 caractères.
2. Ouvrir `https://manager.protaiin.com/maintenance/migrate/<DEPLOY_TOKEN>` : les migrations
   s'exécutent ; sur une base vide, les données de départ (entreprise, compte propriétaire) sont créées.
3. Vider ou changer `DEPLOY_TOKEN` une fois terminé.

### Autre méthode : tâche Cron temporaire

Ajouter **temporairement** une tâche Cron :

```
php /home/<compte>/majestic-depot/artisan migrate --force --seed >> /home/<compte>/majestic-depot/storage/logs/deploy.log 2>&1
```

Attendre une exécution, vérifier `storage/logs/deploy.log`, puis **supprimer** cette tâche.
(Aux mises à jour suivantes, même procédure sans `--seed`.)

## 4. Tâches planifiées (permanentes)

```
* * * * * php /home/<compte>/majestic-depot/artisan schedule:run >> /dev/null 2>&1
* * * * * php /home/<compte>/majestic-depot/artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

La file d'attente utilise la base de données (`QUEUE_CONNECTION=database`) : pas besoin de worker permanent.

## 5. Vérifications

- HTTPS actif (AutoSSL), `APP_DEBUG=false`.
- `https://manager.protaiin.com/.env` doit renvoyer une erreur (fichier hors racine publique).
- Connexion sur `https://manager.protaiin.com/app`.

## API pour Claude (enregistrement des factures et fiches de vente)

1. Dans l'application : **Paramètres › Accès API (Claude)** › « Créer un jeton ». Copiez le jeton (`mjd_…`), il n'est affiché qu'une fois.
2. Transmettez-le à Claude par un canal privé. Révocable à tout moment depuis la même page.
3. Points d'entrée (`/api/v1/…`, en-tête `Authorization: Bearer mjd_…`) :
   - lecture : `catalogue`, `stock`, `journee?date=AAAA-MM-JJ`, `caisse` ;
   - écriture (en-tête `Idempotency-Key` obligatoire) : `fournisseurs`, `clients`, `achats`, `ventes`, `caisse/ouvrir`, `caisse/cloturer`.
   La validation de la clôture de caisse reste faite par le propriétaire dans l'application.
