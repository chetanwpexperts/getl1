#!/usr/bin/env bash
# Publish the latest landing page (landing/public) to getl1.com.
# Run on the VPS as your own user:  bash <base>/getl1-staging/deploy/deploy-landing.sh
set -euo pipefail

SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/getl1-site"   # sibling of the staging checkout
cd "$SITE_ROOT"
git pull --ff-only origin main
git log -1 --format='Landing now at %h %s (%ar)'
echo "Live: https://getl1.com"
