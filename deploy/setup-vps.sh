#!/usr/bin/env bash
# =============================================================================
# GetL1: one-time VPS setup for the landing page (getl1.com) and staging app
# (staging.getl1.com) on a SHARED Ubuntu server.
#
# Safe on a shared box: it only ADDS things (new nginx sites, a new database,
# new folders, one supervisor program, one cron file). It never edits or
# removes existing nginx sites, databases, Node versions or firewall rules.
# Safe to re-run: every step checks before it acts.
#
# Usage (from inside the cloned repo, as your own sudo user, not root login):
#   sudo CERT_EMAIL=you@example.com bash deploy/setup-vps.sh
#
# Optional env vars:
#   STAGING_USER / STAGING_PASS   staging login (asked interactively if unset)
#   SKIP_SSL=1                    don't run certbot yet (e.g. DNS not pointed)
# =============================================================================
set -euo pipefail

DOMAIN="getl1.com"
STAGING_DOMAIN="staging.getl1.com"
REPO_SSH="git@github.com:chetanwpexperts/getl1.git"
APP_ROOT="/var/www/getl1-staging"     # Laravel app (staging)
SITE_ROOT="/var/www/getl1-site"       # checkout used only for landing/public
DATA_DIR="/var/www/getl1-data"        # waitlist CSV (outside every web root)
DB_NAME="getl1_staging"
DB_USER="getl1_staging"
DB_PASS_FILE="/root/.getl1-staging-db-pass"
HTPASSWD="/etc/nginx/.htpasswd-getl1-staging"
MIN_PHP="8.2"

G='\033[0;32m'; Y='\033[1;33m'; R='\033[0;31m'; N='\033[0m'
step() { echo -e "\n${G}==> $*${N}"; }
warn() { echo -e "${Y}[!] $*${N}"; }
die()  { echo -e "${R}[x] $*${N}" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run with sudo: sudo bash deploy/setup-vps.sh"
DEPLOY_USER="${SUDO_USER:-}"
[[ -n "$DEPLOY_USER" && "$DEPLOY_USER" != "root" ]] || die "Run via sudo from your own user (not a root login), so git uses your GitHub key."
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ -f "$REPO_DIR/artisan" ]] || die "Run this from inside the cloned getl1 repo."
[[ "$REPO_DIR" == "$APP_ROOT" ]] || die "Clone the repo to $APP_ROOT first (see deploy/README.md). Found it at $REPO_DIR."

as_user() { sudo -u "$DEPLOY_USER" -H bash -lc "$*"; }
as_web()  { sudo -u www-data -H bash -c "cd '$APP_ROOT' && $*"; }
have()    { command -v "$1" >/dev/null 2>&1; }
apt_install() { DEBIAN_FRONTEND=noninteractive apt-get install -y -q "$@" >/dev/null; }

# -----------------------------------------------------------------------------
step "Checking the server"
. /etc/os-release
[[ "${ID:-}" == "ubuntu" || "${ID:-}" == "debian" ]] || die "Expected Ubuntu/Debian, found ${PRETTY_NAME:-unknown}."
echo "OS: $PRETTY_NAME · deploy user: $DEPLOY_USER"

if ss -ltnp 2>/dev/null | grep -E ':80\s' | grep -q apache2; then
  die "Apache is serving port 80. This script expects nginx. Stop here and tell Claude so the configs can be adapted."
fi
apt-get update -q >/dev/null
for pkg in git unzip curl ca-certificates supervisor apache2-utils; do
  dpkg -s "$pkg" >/dev/null 2>&1 || { echo "installing $pkg"; apt_install "$pkg"; }
done
if ! have nginx; then
  echo "installing nginx"; apt_install nginx
fi
systemctl is-active --quiet nginx || systemctl start nginx
echo "nginx: $(nginx -v 2>&1 | cut -d/ -f2) (existing sites untouched)"

# -----------------------------------------------------------------------------
step "PHP ${MIN_PHP}+ with FPM"
PHPV=""
for v in 8.4 8.3 8.2; do
  if [[ -S /run/php/php${v}-fpm.sock ]] || have "php-fpm${v}"; then PHPV="$v"; break; fi
done
if [[ -z "$PHPV" ]]; then
  echo "No PHP-FPM ${MIN_PHP}+ found, installing PHP 8.3"
  if [[ "$ID" == "ubuntu" ]] && ! apt-cache policy | grep -q ondrej/php; then
    apt_install software-properties-common
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -q >/dev/null
  fi
  PHPV="8.3"
fi
EXTS=(fpm cli mysql mbstring xml curl zip bcmath intl gd sqlite3)
for e in "${EXTS[@]}"; do
  dpkg -s "php${PHPV}-${e}" >/dev/null 2>&1 || { echo "installing php${PHPV}-${e}"; apt_install "php${PHPV}-${e}"; }
done
systemctl enable --now "php${PHPV}-fpm" >/dev/null
PHP_SOCK="/run/php/php${PHPV}-fpm.sock"
PHP_BIN="/usr/bin/php${PHPV}"
[[ -S "$PHP_SOCK" ]] || die "PHP-FPM socket $PHP_SOCK not found."
echo "PHP $PHPV · socket $PHP_SOCK"

# -----------------------------------------------------------------------------
step "Composer"
if ! have composer; then
  EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  ACTUAL="$($PHP_BIN -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  [[ "$EXPECTED" == "$ACTUAL" ]] || die "Composer installer checksum mismatch."
  $PHP_BIN /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
echo "$(composer --version 2>/dev/null | head -1)"

# -----------------------------------------------------------------------------
step "Node.js (for building CSS/JS)"
# Never upgrade/replace an existing Node: other apps on this server depend on it.
NODE_OK=0
if as_user "command -v node" >/dev/null 2>&1; then
  NODE_MAJOR="$(as_user 'node -v' | sed 's/v\([0-9]*\).*/\1/')"
  if (( NODE_MAJOR >= 18 )); then NODE_OK=1; echo "Using existing Node $(as_user 'node -v')"; fi
fi
if (( NODE_OK == 0 )); then
  if have node; then
    warn "Node < 18 is installed system-wide and other apps may use it. Not touching it."
    warn "Install nvm for $DEPLOY_USER and 'nvm install 22', then re-run this script."
  else
    echo "installing Node 22 (NodeSource)"
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null
    apt_install nodejs
    NODE_OK=1
  fi
fi

# -----------------------------------------------------------------------------
step "MySQL database: $DB_NAME"
if ! have mysql; then
  echo "installing mysql-server"; apt_install mysql-server
  systemctl enable --now mysql >/dev/null
fi
if [[ -f "$DB_PASS_FILE" ]]; then
  DB_PASS="$(cat "$DB_PASS_FILE")"
else
  DB_PASS="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 28)"
  umask 077; echo "$DB_PASS" > "$DB_PASS_FILE"; umask 022
fi
mysql --protocol=socket -uroot <<SQL || die "Could not create the database. If MySQL root needs a password, run: sudo mysql -uroot -p and create $DB_NAME / $DB_USER manually (see deploy/README.md)."
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "Database ready (password stored in $DB_PASS_FILE, root-only)"

# -----------------------------------------------------------------------------
step "Landing page checkout: $SITE_ROOT"
if [[ ! -d "$SITE_ROOT/.git" ]]; then
  mkdir -p "$SITE_ROOT"; chown "$DEPLOY_USER":"$DEPLOY_USER" "$SITE_ROOT"
  as_user "git clone --depth 1 '$REPO_SSH' '$SITE_ROOT'"
else
  as_user "cd '$SITE_ROOT' && git pull --ff-only"
fi
mkdir -p "$DATA_DIR"
chown www-data:www-data "$DATA_DIR"; chmod 750 "$DATA_DIR"
echo "Waitlist will be saved to $DATA_DIR/waitlist.csv"

# -----------------------------------------------------------------------------
step "Staging app: $APP_ROOT"
usermod -aG www-data "$DEPLOY_USER"   # lets your user read logs/.env that www-data owns
chown -R "$DEPLOY_USER":www-data "$APP_ROOT"
FIRST_INSTALL=0
if [[ ! -f "$APP_ROOT/.env" ]]; then
  FIRST_INSTALL=1
  as_user "cp '$APP_ROOT/.env.example' '$APP_ROOT/.env'"
fi
set_env() { # key value
  local k="$1" v="$2" f="$APP_ROOT/.env"
  if grep -qE "^#?\s*${k}=" "$f"; then sed -i -E "s|^#?\s*${k}=.*|${k}=${v}|" "$f"; else echo "${k}=${v}" >> "$f"; fi
}
set_env APP_NAME "\"GetL1 Staging\""
set_env APP_ENV staging
set_env APP_DEBUG true
set_env APP_URL "https://$STAGING_DOMAIN"
set_env LOG_STACK daily
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASS"
set_env SESSION_DRIVER database
set_env SESSION_SECURE_COOKIE true
set_env QUEUE_CONNECTION database
set_env CACHE_STORE database
set_env MAIL_MAILER log
chmod 640 "$APP_ROOT/.env"; chown "$DEPLOY_USER":www-data "$APP_ROOT/.env"

as_user "cd '$APP_ROOT' && composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress"
chown -R www-data:www-data "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"
chmod -R ug+rwX "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache"

grep -qE '^APP_KEY=base64:' "$APP_ROOT/.env" || as_user "cd '$APP_ROOT' && $PHP_BIN artisan key:generate --force"
as_web "$PHP_BIN artisan migrate --force"
as_web "$PHP_BIN artisan db:seed --force"                       # plans + categories (idempotent)
if (( FIRST_INSTALL == 1 )); then
  as_web "$PHP_BIN artisan db:seed --class=DemoSeeder --force"  # demo buyer + 5 suppliers, once
fi
[[ -L "$APP_ROOT/public/storage" ]] || as_web "$PHP_BIN artisan storage:link"

if (( NODE_OK == 1 )); then
  as_user "cd '$APP_ROOT' && (npm ci --no-audit --no-fund 2>/dev/null || npm install --no-audit --no-fund) && npm run build"
else
  warn "Skipped asset build (no Node 18+). Pages will load without styles until you build."
fi
as_web "$PHP_BIN artisan optimize:clear >/dev/null && $PHP_BIN artisan config:cache && $PHP_BIN artisan route:cache && $PHP_BIN artisan view:cache"

# -----------------------------------------------------------------------------
step "Queue worker (supervisor) + scheduler (cron)"
cat > /etc/supervisor/conf.d/getl1-staging-worker.conf <<EOF
[program:getl1-staging-worker]
command=$PHP_BIN $APP_ROOT/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
directory=$APP_ROOT
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
redirect_stderr=true
stdout_logfile=$APP_ROOT/storage/logs/worker.log
stopwaitsecs=3600
EOF
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
supervisorctl restart getl1-staging-worker >/dev/null 2>&1 || supervisorctl start getl1-staging-worker >/dev/null
echo "* * * * * www-data cd $APP_ROOT && $PHP_BIN artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/getl1-staging
chmod 644 /etc/cron.d/getl1-staging
echo "Worker: $(supervisorctl status getl1-staging-worker | awk '{print $2}')"

# -----------------------------------------------------------------------------
step "Staging password (browser login for you and testers)"
if [[ ! -f "$HTPASSWD" ]]; then
  SU="${STAGING_USER:-}"; SP="${STAGING_PASS:-}"
  [[ -n "$SU" ]] || read -rp "Staging username: " SU
  [[ -n "$SP" ]] || { read -rsp "Staging password: " SP; echo; }
  [[ -n "$SU" && -n "$SP" ]] || die "Staging username/password required."
  htpasswd -bcB "$HTPASSWD" "$SU" "$SP" >/dev/null
  chown root:www-data "$HTPASSWD"; chmod 640 "$HTPASSWD"
  echo "Created login '$SU'. Add more later: sudo htpasswd -B $HTPASSWD <name>"
else
  echo "Existing $HTPASSWD kept"
fi

# -----------------------------------------------------------------------------
step "nginx sites (new files only)"
install_site() { # template name root_placeholder root_value
  local tpl="$1" name="$2" ph="$3" val="$4" dst="/etc/nginx/sites-available/$2"
  if [[ -f "$dst" ]] && grep -q "managed-by-certbot" "$dst"; then
    echo "$name already has SSL from certbot, leaving it as is"
    return
  fi
  sed -e "s|__PHP_SOCK__|$PHP_SOCK|g" -e "s|$ph|$val|g" "$tpl" > "$dst"
  ln -sf "$dst" "/etc/nginx/sites-enabled/$name"
}
install_site "$APP_ROOT/deploy/nginx/getl1.com.conf" "getl1.com" "__SITE_ROOT__" "$SITE_ROOT"
install_site "$APP_ROOT/deploy/nginx/staging.getl1.com.conf" "staging.getl1.com" "__APP_ROOT__" "$APP_ROOT"
if ! nginx -t 2>/tmp/getl1-nginx-test; then
  cat /tmp/getl1-nginx-test
  rm -f /etc/nginx/sites-enabled/getl1.com /etc/nginx/sites-enabled/staging.getl1.com
  die "nginx config test failed. GetL1 sites were disabled again; other sites are unaffected."
fi
systemctl reload nginx
echo "nginx reloaded"

# -----------------------------------------------------------------------------
step "SSL certificates (Let's Encrypt)"
SERVER_IP="$(curl -fsS4 https://api.ipify.org || hostname -I | awk '{print $1}')"
dns_ok() { getent ahostsv4 "$1" | awk '{print $1}' | grep -qx "$SERVER_IP"; }
if [[ "${SKIP_SSL:-0}" == "1" ]]; then
  warn "SKIP_SSL=1, skipping certbot."
elif [[ -z "${CERT_EMAIL:-}" ]]; then
  warn "CERT_EMAIL not set, skipping certbot. Re-run with: sudo CERT_EMAIL=you@example.com bash deploy/setup-vps.sh"
else
  dpkg -s python3-certbot-nginx >/dev/null 2>&1 || apt_install certbot python3-certbot-nginx
  MAIN_HOSTS=(); for h in "$DOMAIN" "www.$DOMAIN"; do dns_ok "$h" && MAIN_HOSTS+=(-d "$h") || warn "$h does not point to $SERVER_IP yet"; done
  if (( ${#MAIN_HOSTS[@]} > 0 )); then
    certbot --nginx --non-interactive --agree-tos -m "$CERT_EMAIL" --redirect --cert-name "$DOMAIN" "${MAIN_HOSTS[@]}" || warn "certbot failed for $DOMAIN"
  fi
  if dns_ok "$STAGING_DOMAIN"; then
    certbot --nginx --non-interactive --agree-tos -m "$CERT_EMAIL" --redirect --cert-name "$STAGING_DOMAIN" -d "$STAGING_DOMAIN" || warn "certbot failed for $STAGING_DOMAIN"
  else
    warn "$STAGING_DOMAIN does not point to $SERVER_IP yet"
  fi
fi

# -----------------------------------------------------------------------------
step "Done"
cat <<EOF
  Landing:   https://$DOMAIN            (files: $SITE_ROOT/landing/public)
  Staging:   https://$STAGING_DOMAIN    (app: $APP_ROOT, password-protected)
  Waitlist:  sudo cat $DATA_DIR/waitlist.csv
  Deploy:    bash $APP_ROOT/deploy/deploy-staging.sh   ·   bash $APP_ROOT/deploy/deploy-landing.sh
  Demo app logins (password "password"): buyer@getl1.test, supplier1@getl1.test … supplier5@getl1.test
EOF
