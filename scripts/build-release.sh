#!/usr/bin/env bash
# Prépare une archive prête à envoyer sur cPanel (sans SSH) :
# dépendances de production incluses, sans fichiers de développement.
# Usage : scripts/build-release.sh  → build/majestic-depot-<date>.zip
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M)"
WORK="$ROOT/build/release"
OUT="$ROOT/build/majestic-depot-$STAMP.zip"

rm -rf "$WORK" && mkdir -p "$WORK"
git -C "$ROOT" archive HEAD | tar -x -C "$WORK"

cd "$WORK"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
php artisan filament:assets --no-interaction >/dev/null
rm -rf tests phpunit.xml .github node_modules build
# Dépendances installées depuis les sources : on retire leurs dépôts git.
find vendor -name .git -type d -prune -exec rm -rf {} +

cd "$ROOT/build/release" && zip -qr "$OUT" . -x '.env'
echo "Archive prête : $OUT"
