#!/usr/bin/env bash
# Deploy the latest `main` to staging.getl1.com.
# Run on the VPS as your own user:  bash <base>/getl1-staging/deploy/deploy-staging.sh
# (asks for your sudo password once, to run artisan as www-data)
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"   # this repo checkout
BRANCH="${1:-main}"
PHP_BIN="$(ls /usr/bin/php[0-9]* 2>/dev/null | grep -E "php[0-9]+\.[0-9]+$" | sort -V | tail -1)"
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
COMPOSER_FLAGS=(--no-interaction --prefer-dist --optimize-autoloader --no-progress --no-scripts)
# A package was added to composer.json since the lock was written: resolve only what changed.
DRY_RUN="$(composer install --dry-run "${COMPOSER_FLAGS[@]}" 2>&1 || true)"   # exits non-zero when the lock is stale
if [[ ! -f composer.lock ]] || grep -qiE "not up to date|not present in the lock file" <<<"$DRY_RUN"; then
  echo "    composer.json changed, updating the lock file"
  composer update --minimal-changes "${COMPOSER_FLAGS[@]}" 2>/dev/null || composer update "${COMPOSER_FLAGS[@]}"
fi
composer install "${COMPOSER_FLAGS[@]}"
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
# Live auctions: restart the websocket server so it loads the new code (no-op if not enabled yet).
if web list --raw 2>/dev/null | grep -q '^reverb:restart'; then web reverb:restart >/dev/null || true; fi

web up
trap - EXIT
echo "==> Staging is live: https://staging.getl1.com"
