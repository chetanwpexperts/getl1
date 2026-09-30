#!/usr/bin/env bash
# Deploy the latest `main` to staging.getl1.com.
# Run on the VPS as your own user:  bash /var/www/getl1-staging/deploy/deploy-staging.sh
# (asks for your sudo password once, to run artisan as www-data)
set -euo pipefail

APP_ROOT="/var/www/getl1-staging"
BRANCH="${1:-main}"
PHP_BIN="$(ls /usr/bin/php8.4 /usr/bin/php8.3 /usr/bin/php8.2 2>/dev/null | head -1)"
[[ -n "$PHP_BIN" ]] || { echo "PHP 8.2+ not found"; exit 1; }
web() { sudo -u www-data "$PHP_BIN" "$APP_ROOT/artisan" "$@"; }

cd "$APP_ROOT"
echo "==> Pulling $BRANCH"
git fetch origin "$BRANCH"
git checkout -q "$BRANCH"
git pull --ff-only origin "$BRANCH"
git log -1 --format='    %h %s (%an, %ar)'

echo "==> Maintenance mode"
web down --retry=15 || true
trap 'web up >/dev/null 2>&1 || true' EXIT

echo "==> Composer"
composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress --no-scripts
web package:discover --ansi >/dev/null

echo "==> Migrations"
web migrate --force

echo "==> Assets"
if command -v npm >/dev/null 2>&1; then
  (npm ci --no-audit --no-fund 2>/dev/null || npm install --no-audit --no-fund) >/dev/null
  npm run build >/dev/null
else
  echo "    npm not found, skipping asset build"
fi

echo "==> Caches"
web optimize:clear >/dev/null
web config:cache >/dev/null
web route:cache >/dev/null
web view:cache >/dev/null
web queue:restart >/dev/null

web up
trap - EXIT
echo "==> Staging is live: https://staging.getl1.com"
