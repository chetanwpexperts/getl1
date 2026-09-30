#!/usr/bin/env bash
# One-time setup for live auctions on staging (safe to re-run).
#
#   1. bash <app>/deploy/deploy-staging.sh           (installs laravel/reverb)
#   2. sudo bash <app>/deploy/enable-live-auctions.sh
#
# What it adds to the server, and nothing else:
#   - Redis (only if not installed yet), listening on localhost only. An existing Redis
#     is reused as is: GetL1 uses its own DB number (5) and its own pub/sub channel name.
#   - One supervisor program: getl1-staging-reverb (websocket server on 127.0.0.1).
#   - One location block (/app/) inside the staging.getl1.com nginx site.
#   - Reverb keys in the app's .env.
# It does not touch other sites, PHP-FPM or any existing Redis configuration.
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITE="/etc/nginx/sites-available/staging.getl1.com"
ENV_FILE="$APP_ROOT/.env"
PROGRAM="getl1-staging-reverb"
STAGING_HOST="${STAGING_DOMAIN:-staging.getl1.com}"

step() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m    ! %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run with sudo."
[[ -f "$ENV_FILE" ]] || die "$ENV_FILE not found. Run deploy/setup-vps.sh first."
[[ -f "$SITE" ]] || die "$SITE not found. Run deploy/setup-vps.sh first."
DEPLOY_USER="${SUDO_USER:-$(stat -c %U "$APP_ROOT")}"
PHP_BIN="$(ls /usr/bin/php[0-9]* 2>/dev/null | grep -E "php[0-9]+\.[0-9]+$" | sort -V | tail -1)"
[[ -n "$PHP_BIN" ]] || die "PHP not found."
web() { sudo -u www-data "$PHP_BIN" "$APP_ROOT/artisan" "$@"; }

web list --raw 2>/dev/null | grep -q '^reverb:start' \
  || die "Reverb isn't installed yet. Run: bash $APP_ROOT/deploy/deploy-staging.sh (as your user), then this script again."

# -----------------------------------------------------------------------------
step "Redis"
if ! command -v redis-server >/dev/null 2>&1; then
  echo "    Installing redis-server (localhost only)"
  DEBIAN_FRONTEND=noninteractive apt-get install -y -q redis-server >/dev/null
  systemctl enable --now redis-server >/dev/null 2>&1 || true
else
  echo "    Redis already installed, reusing it without changes"
fi
REDIS_PASS="${REDIS_PASSWORD:-}"
redis_cli() { if [[ -n "$REDIS_PASS" ]]; then REDISCLI_AUTH="$REDIS_PASS" redis-cli "$@"; else redis-cli "$@"; fi; }
PONG="$(redis_cli -h 127.0.0.1 ping 2>&1 || true)"
if [[ "$PONG" == *NOAUTH* ]]; then
  die "Redis needs a password. Re-run with: sudo REDIS_PASSWORD='...' bash $0"
fi
[[ "$PONG" == "PONG" ]] || die "Redis isn't answering on 127.0.0.1:6379 ($PONG)."
BIND="$(redis_cli -h 127.0.0.1 config get bind 2>/dev/null | tail -1 || true)"
[[ -z "$BIND" || "$BIND" =~ ^(127\.0\.0\.1|-?::1|\ )+$ ]] || warn "Redis bind is '$BIND'. Make sure port 6379 is firewalled from the internet."
echo "    Redis OK"

# -----------------------------------------------------------------------------
step "App settings (.env)"
cp -a "$ENV_FILE" "$ENV_FILE.bak-live-auctions"
env_get() { grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
env_set() { # key value [force]
  local k="$1" v="$2" force="${3:-}"
  if grep -qE "^$k=" "$ENV_FILE"; then
    [[ -n "$force" || -z "$(env_get "$k")" ]] || return 0
    sed -i "s|^$k=.*|$k=$v|" "$ENV_FILE"
  else
    printf '%s=%s\n' "$k" "$v" >> "$ENV_FILE"
  fi
}
rand() { tr -dc "$1" </dev/urandom | head -c "$2" || true; }

PORT="$(env_get REVERB_SERVER_PORT)"
if [[ -z "$PORT" ]]; then
  PORT=8090
  while ss -ltnH "( sport = :$PORT )" | grep -q .; do PORT=$((PORT + 1)); done
fi
echo "    Websocket server port (localhost only): $PORT"

env_set BROADCAST_CONNECTION reverb force
env_set REVERB_APP_ID "$(rand '0-9' 6)"
env_set REVERB_APP_KEY "$(rand 'a-z0-9' 20)"
env_set REVERB_APP_SECRET "$(rand 'a-z0-9' 32)"
env_set REVERB_HOST "$STAGING_HOST"
env_set REVERB_PORT 443
env_set REVERB_SCHEME https
env_set REVERB_SERVER_HOST 127.0.0.1
env_set REVERB_SERVER_PORT "$PORT" force
env_set REVERB_INTERNAL_HOST 127.0.0.1
env_set REVERB_ALLOWED_ORIGINS "$STAGING_HOST"
env_set REVERB_SCALING_ENABLED true force
env_set REDIS_HOST 127.0.0.1
env_set REDIS_PORT 6379
[[ -n "$REDIS_PASS" ]] && env_set REDIS_PASSWORD "$REDIS_PASS" force
env_set REDIS_DB 5
env_set REDIS_PREFIX getl1_
env_set VITE_REVERB_APP_KEY '"${REVERB_APP_KEY}"'
env_set VITE_REVERB_HOST '"${REVERB_HOST}"'
env_set VITE_REVERB_PORT '"${REVERB_PORT}"'
env_set VITE_REVERB_SCHEME '"${REVERB_SCHEME}"'
chown "$DEPLOY_USER":www-data "$ENV_FILE"; chmod 640 "$ENV_FILE"
echo "    Saved (backup: .env.bak-live-auctions)"

# -----------------------------------------------------------------------------
step "Websocket server (supervisor: $PROGRAM)"
cat > "/etc/supervisor/conf.d/$PROGRAM.conf" <<EOF
[program:$PROGRAM]
command=$PHP_BIN $APP_ROOT/artisan reverb:start --host=127.0.0.1 --port=$PORT --no-interaction
directory=$APP_ROOT
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
minfds=10000
redirect_stderr=true
stdout_logfile=$APP_ROOT/storage/logs/reverb.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
stopwaitsecs=10
EOF

# -----------------------------------------------------------------------------
step "nginx: /app/ websocket proxy on $STAGING_HOST"
BACKUP="/root/staging.getl1.com.nginx.bak-$(date +%Y%m%d%H%M%S)"
cp -a "$SITE" "$BACKUP"
if grep -q "getl1-reverb" "$SITE"; then
  sed -i -E "/getl1-reverb/,/^    }/ s|proxy_pass http://127\.0\.0\.1:[0-9]+;|proxy_pass http://127.0.0.1:$PORT;|" "$SITE"
  echo "    Block already present, port set to $PORT"
else
  BLOCK="$(mktemp)"
  cat > "$BLOCK" <<EOF
    # getl1-reverb: live auction websockets -> Reverb on localhost.
    # No basic auth here: browsers don't send it on websockets. Private channels are still
    # authorised per user by /broadcasting/auth, which stays behind the login.
    location ^~ /app/ {
        auth_basic off;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 120s;
        proxy_pass http://127.0.0.1:$PORT;
    }

EOF
  # Insert before the first "location / {" (the HTTPS server block in a certbot-managed file).
  awk -v blk="$BLOCK" '!done && /^[[:space:]]*location \/ \{/ { while ((getline l < blk) > 0) print l; done=1 } { print }' "$BACKUP" > "$SITE"
  rm -f "$BLOCK"
  grep -q "getl1-reverb" "$SITE" || { cp -a "$BACKUP" "$SITE"; die "Couldn't find 'location / {' in $SITE. Nothing changed."; }
  echo "    Block added"
fi
if ! nginx -t 2>/tmp/getl1-nginx-test; then
  cat /tmp/getl1-nginx-test
  cp -a "$BACKUP" "$SITE"
  die "nginx test failed, restored the previous staging config. Other sites were not touched."
fi
systemctl reload nginx
echo "    nginx reloaded (backup: $BACKUP)"

# -----------------------------------------------------------------------------
step "Build and restart"
sudo -u "$DEPLOY_USER" -H bash -c "cd '$APP_ROOT' && npm run build >/dev/null"
web config:cache >/dev/null
web queue:restart >/dev/null
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
supervisorctl restart "$PROGRAM" >/dev/null 2>&1 || supervisorctl start "$PROGRAM" >/dev/null
sleep 2

CODE="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/up" || true)"
if [[ "$CODE" == "200" ]]; then
  echo "    Websocket server is up"
else
  warn "Websocket server didn't answer (HTTP $CODE). Check: sudo tail -50 $APP_ROOT/storage/logs/reverb.log"
  warn "The auction pages still work without it (they update every 2 seconds)."
fi

step "Done"
cat <<EOF
  Live auctions are enabled on https://$STAGING_HOST
  Status:  sudo supervisorctl status $PROGRAM
  Log:     sudo tail -f $APP_ROOT/storage/logs/reverb.log
  Undo:    sudo cp $BACKUP $SITE && sudo systemctl reload nginx
           sudo supervisorctl stop $PROGRAM && sudo rm /etc/supervisor/conf.d/$PROGRAM.conf && sudo supervisorctl update
           set BROADCAST_CONNECTION=log in .env, then: sudo -u www-data php artisan config:cache
EOF
