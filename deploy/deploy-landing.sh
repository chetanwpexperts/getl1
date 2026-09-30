#!/usr/bin/env bash
# Publish the latest landing page (landing/public) to getl1.com.
# Run on the VPS as your own user:  bash /var/www/getl1-staging/deploy/deploy-landing.sh
set -euo pipefail

SITE_ROOT="/var/www/getl1-site"
cd "$SITE_ROOT"
git pull --ff-only origin main
git log -1 --format='Landing now at %h %s (%ar)'
echo "Live: https://getl1.com"
