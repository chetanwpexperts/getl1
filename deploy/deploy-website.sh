#!/usr/bin/env bash
# Update getl1.com (the getl1-www checkout) to the latest `main`.
# Run on the VPS as your own user:  bash <base>/getl1-www/deploy/deploy-website.sh
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ "$(basename "$APP_ROOT")" == "getl1-www" ]] || { echo "Run this from the getl1-www checkout."; exit 1; }
PHP_BIN="$(ls /usr/bin/php[0-9]* 2>/dev/null | grep -E "php[0-9]+\.[0-9]+$" | sort -V | tail -1)"
web() { sudo -u www-data "$PHP_BIN" "$APP_ROOT/artisan" "$@"; }

cd "$APP_ROOT"
git fetch -q origin main && git checkout -q main && git pull -q --ff-only origin main
git log -1 --format='==> %h %s (%ar)'
web down --retry=15 || true
trap 'web up >/dev/null 2>&1 || true' EXIT
# Use exactly the package versions tested on staging.
[[ -f "$(dirname "$APP_ROOT")/getl1-staging/composer.lock" ]] && cp "$(dirname "$APP_ROOT")/getl1-staging/composer.lock" composer.lock
composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress --no-dev --no-scripts
sudo -u www-data rm -f "$APP_ROOT/bootstrap/cache/config.php"
web package:discover --ansi >/dev/null
web migrate --force
web db:seed --class=PlanSeeder --force >/dev/null
(npm ci --no-audit --no-fund 2>/dev/null || npm install --no-audit --no-fund) >/dev/null && npm run build >/dev/null
[[ -L "$APP_ROOT/public/storage" ]] || ln -s ../storage/app/public "$APP_ROOT/public/storage"   # logo, favicon and share image uploads
web optimize:clear >/dev/null
web config:cache >/dev/null; web route:cache >/dev/null; web view:cache >/dev/null
web up; trap - EXIT
echo "==> getl1.com is live"
