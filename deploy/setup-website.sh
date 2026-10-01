#!/usr/bin/env bash
# =============================================================================
# GetL1: put the new website on getl1.com (replaces the coming-soon page).
#
# Creates a separate checkout <base>/getl1-www running the same app with
# SITE_MODE=website: only the public pages and the early-access form answer;
# login, signup and the product return 404 there until launch.
#
# Only touches GetL1 things: the getl1.com nginx site (backed up first and
# restored automatically if anything fails), a new getl1_www database, and the
# new folder. Other sites, databases and services are not touched.
# Safe to re-run.
#
# Usage (as your own user, from the staging checkout):
#   sudo LEADS_TO=you@gmail.com bash <base>/getl1-staging/deploy/setup-website.sh
# =============================================================================
set -euo pipefail

DOMAIN="getl1.com"
STAGING_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE="$(dirname "$STAGING_ROOT")"
WWW_ROOT="$BASE/getl1-www"
DATA_DIR="$BASE/getl1-data"
DB_NAME="getl1_www"; DB_USER="getl1_www"; DB_PASS_FILE="/root/.getl1-www-db-pass"
NGINX_SITE="/etc/nginx/sites-available/getl1.com"
BACKUP_DIR="/root/getl1-backups"

G='\033[0;32m'; Y='\033[1;33m'; R='\033[0;31m'; N='\033[0m'
step() { echo -e "\n${G}==> $*${N}"; }
warn() { echo -e "${Y}[!] $*${N}"; }
die()  { echo -e "${R}[x] $*${N}" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run with sudo: sudo LEADS_TO=you@example.com bash $0"
DEPLOY_USER="${SUDO_USER:-}"
[[ -n "$DEPLOY_USER" && "$DEPLOY_USER" != "root" ]] || die "Run via sudo from your own user (not a root login)."
[[ "$(basename "$STAGING_ROOT")" == "getl1-staging" ]] || die "Run this from the getl1-staging checkout."
case "$WWW_ROOT" in *outraqhq*) die "Refusing to work inside an OutraqHQ folder.";; esac

PHP_BIN="$(ls /usr/bin/php[0-9]* 2>/dev/null | grep -E "php[0-9]+\.[0-9]+$" | sort -V | tail -1)"
[[ -n "$PHP_BIN" ]] || die "PHP 8.2+ not found"
PHPV="${PHP_BIN##*php}"; PHP_SOCK="/run/php/php${PHPV}-fpm.sock"
[[ -S "$PHP_SOCK" ]] || die "PHP-FPM socket $PHP_SOCK not found."

as_user() { sudo -u "$DEPLOY_USER" -H bash -lc "$*"; }
web()     { sudo -u www-data "$PHP_BIN" "$WWW_ROOT/artisan" "$@"; }
env_get() { grep -E "^$1=" "$STAGING_ROOT/.env" | tail -1 | cut -d= -f2- || true; }

# -----------------------------------------------------------------------------
step "Code: $WWW_ROOT"
REMOTE="$(as_user "git -C '$STAGING_ROOT' remote get-url origin")"
if [[ ! -d "$WWW_ROOT/.git" ]]; then
  install -d -o "$DEPLOY_USER" -g www-data "$WWW_ROOT"
  as_user "git clone -q '$REMOTE' '$WWW_ROOT'"
else
  as_user "cd '$WWW_ROOT' && git fetch -q origin main && git checkout -q main && git pull -q --ff-only origin main"
fi
as_user "git -C '$WWW_ROOT' log -1 --format='    %h %s (%ar)'"
chown -R "$DEPLOY_USER":www-data "$WWW_ROOT"

# -----------------------------------------------------------------------------
step "Database: $DB_NAME"
if [[ -f "$DB_PASS_FILE" ]]; then DB_PASS="$(cat "$DB_PASS_FILE")"; else
  DB_PASS="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 28)"; umask 077; echo "$DB_PASS" > "$DB_PASS_FILE"; umask 022
fi
mysql --protocol=socket -uroot <<SQL || die "Could not create the database (MySQL root via socket)."
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "    ready (password in $DB_PASS_FILE, root-only)"

# -----------------------------------------------------------------------------
step "Settings (.env)"
ENV="$WWW_ROOT/.env"
[[ -f "$ENV" ]] || as_user "cp '$WWW_ROOT/.env.example' '$ENV'"
# Literal replace (values such as SMTP passwords may contain | & / characters).
set_env() {
  K="$1" V="$2" F="$ENV" "$PHP_BIN" -r '
    $f = getenv("F"); $k = getenv("K"); $line = $k."=".getenv("V");
    $lines = file_exists($f) ? preg_split("/\R/", rtrim(file_get_contents($f), "\n")) : [];
    $done = false;
    foreach ($lines as $i => $l) { if (preg_match("/^#?\s*".preg_quote($k, "/")."=/", $l)) { $lines[$i] = $line; $done = true; break; } }
    if (! $done) { $lines[] = $line; }
    file_put_contents($f, implode("\n", $lines)."\n");'
}
set_env APP_NAME GetL1
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://$DOMAIN"
set_env SITE_MODE website
set_env LOG_STACK daily
set_env LOG_LEVEL warning
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASS"
set_env SESSION_DRIVER database
set_env SESSION_SECURE_COOKIE true
set_env CACHE_STORE database
set_env QUEUE_CONNECTION sync          # website mode only sends the two lead emails; no worker needed
set_env BROADCAST_CONNECTION log
set_env MAIL_ALLOWLIST ""
# Same mailbox as staging (Hostinger SMTP), copied without printing secrets.
for k in MAIL_MAILER MAIL_SCHEME MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_ENCRYPTION MAIL_FROM_ADDRESS MAIL_FROM_NAME; do
  v="$(env_get "$k")"; [[ -n "$v" ]] && set_env "$k" "$v"
done
set_env MAIL_FROM_NAME GetL1
LEADS="${LEADS_TO:-$(env_get MAIL_FROM_ADDRESS)}"
[[ -n "$LEADS" ]] && set_env SITE_LEADS_TO "$LEADS"
for k in SITE_EMAIL SITE_PHONE SITE_LEGAL_NAME SITE_ADDRESS SITE_GRIEVANCE_OFFICER BILLING_SELLER_NAME BILLING_SELLER_ADDRESS; do
  v="$(env_get "$k")"; [[ -n "$v" ]] && set_env "$k" "$v"
done
chmod 640 "$ENV"; chown "$DEPLOY_USER":www-data "$ENV"
echo "    production, website mode, leads emailed to ${LEADS:-(not set)}"

# -----------------------------------------------------------------------------
step "Install"
# Use exactly the package versions tested on staging.
[[ -f "$STAGING_ROOT/composer.lock" ]] && install -o "$DEPLOY_USER" -g www-data -m 644 "$STAGING_ROOT/composer.lock" "$WWW_ROOT/composer.lock"
as_user "cd '$WWW_ROOT' && composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress --no-dev"
chown -R www-data:www-data "$WWW_ROOT/storage" "$WWW_ROOT/bootstrap/cache"
chmod -R ug+rwX "$WWW_ROOT/storage" "$WWW_ROOT/bootstrap/cache"
grep -qE '^APP_KEY=base64:' "$ENV" || as_user "cd '$WWW_ROOT' && $PHP_BIN artisan key:generate --force"
web migrate --force
web db:seed --class=PlanSeeder --force >/dev/null
as_user "cd '$WWW_ROOT' && (npm ci --no-audit --no-fund 2>/dev/null || npm install --no-audit --no-fund) >/dev/null && npm run build >/dev/null"
if [[ -f "$DATA_DIR/waitlist.csv" ]]; then
  cp "$DATA_DIR/waitlist.csv" /tmp/getl1-waitlist.csv && chown www-data /tmp/getl1-waitlist.csv
  web getl1:import-waitlist /tmp/getl1-waitlist.csv; rm -f /tmp/getl1-waitlist.csv
fi
web optimize:clear >/dev/null
web config:cache >/dev/null; web route:cache >/dev/null; web view:cache >/dev/null

# -----------------------------------------------------------------------------
step "nginx: switch getl1.com to the new website"
install -d -m 700 "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP=""
if [[ -f "$NGINX_SITE" ]]; then BACKUP="$BACKUP_DIR/getl1.com.$STAMP.conf"; cp -a "$NGINX_SITE" "$BACKUP"; echo "    backup: $BACKUP"; fi
restore() {
  warn "Restoring the previous getl1.com site."
  if [[ -n "$BACKUP" ]]; then cp -a "$BACKUP" "$NGINX_SITE"; fi
  nginx -t >/dev/null 2>&1 && systemctl reload nginx
  die "$1"
}
sed -e "s|__PHP_SOCK__|$PHP_SOCK|g" -e "s|__APP_ROOT__|$WWW_ROOT|g" "$STAGING_ROOT/deploy/nginx/getl1.com-app.conf" > "$NGINX_SITE"
ln -sf "$NGINX_SITE" /etc/nginx/sites-enabled/getl1.com
nginx -t 2>/tmp/getl1-nginx-test || { cat /tmp/getl1-nginx-test; restore "nginx config test failed."; }

if [[ -d "/etc/letsencrypt/live/$DOMAIN" ]]; then
  # Re-attach the existing certificate to the new config (no new certificate is issued).
  certbot install --nginx --non-interactive --cert-name "$DOMAIN" --redirect >/dev/null 2>&1 \
    || certbot install --nginx --non-interactive --cert-name "$DOMAIN" --redirect \
    || restore "certbot could not attach the existing certificate."
else
  [[ -n "${CERT_EMAIL:-}" ]] || restore "No certificate for $DOMAIN yet. Re-run with CERT_EMAIL=you@example.com."
  certbot --nginx --non-interactive --agree-tos -m "$CERT_EMAIL" --redirect --cert-name "$DOMAIN" -d "$DOMAIN" -d "www.$DOMAIN" \
    || restore "certbot failed."
fi
nginx -t >/dev/null 2>&1 || restore "nginx config test failed after SSL."
systemctl reload nginx

# -----------------------------------------------------------------------------
step "Checking"
sleep 1
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$1"; }
HOME_CODE="$(code "https://$DOMAIN/")"; LOGIN_CODE="$(code "https://$DOMAIN/login")"; WWW_CODE="$(code "https://www.$DOMAIN/")"
echo "    https://$DOMAIN/        → $HOME_CODE (expect 200)"
echo "    https://www.$DOMAIN/    → $WWW_CODE (expect 301 to getl1.com)"
echo "    https://$DOMAIN/login   → $LOGIN_CODE (expect 404 until launch)"
[[ "$HOME_CODE" == "200" ]] || restore "The new website didn't answer with 200. Check: sudo tail -50 $WWW_ROOT/storage/logs/laravel-$(date +%F).log"

cat <<DONE

Website is live: https://$DOMAIN
  Leads:   admin console on staging is separate; on this site they're emailed to ${LEADS:-SITE_LEADS_TO}
           and stored in the $DB_NAME database.
  Update:  bash $WWW_ROOT/deploy/deploy-website.sh
  Undo:    sudo cp $BACKUP $NGINX_SITE && sudo systemctl reload nginx
DONE
