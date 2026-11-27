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

Créer la base et l'utilisateur dans « Bases de données MySQL ».
Pour lancer les migrations sans terminal, ajouter **temporairement** une tâche Cron :

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
