#!/usr/bin/env bash
# Launch: switch getl1.com from the website-only mode to the full app (signup, RFQs, live auctions, payments).
#
#   cd /cdata/getl1-www && git pull && sudo bash deploy/switch-to-app.sh
#
# What it changes, and nothing else:
#   - getl1-www/.env: SITE_MODE=app, background email queue, live auction settings,
#     AI key copied from staging if missing, a Razorpay webhook secret if missing (backup kept).
#   - Two supervisor programs: getl1-www-worker (emails, AI) and getl1-www-reverb (live auctions, localhost only).
#   - One cron file: /etc/cron.d/getl1-www (scheduler: auction start/close, reminders, health checks).
#   - One location block (/app/) in the getl1.com nginx site, for live auction connections (backup kept).
# It does not touch staging, OutraqHQ, your partner's sites, PHP-FPM or any Redis.
# Safe to re-run. Undo steps are printed at the end.
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGING_ROOT="$(dirname "$APP_ROOT")/getl1-staging"
ENV_FILE="$APP_ROOT/.env"
SITE="/etc/nginx/sites-available/getl1.com"
HOST="getl1.com"
STAMP="$(date +%Y%m%d%H%M%S)"

step() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m    ! %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run with sudo."
[[ "$(basename "$APP_ROOT")" == "getl1-www" ]] || die "Run this from the getl1-www checkout."
[[ -f "$ENV_FILE" ]] || die "$ENV_FILE not found."
[[ -f "$SITE" ]] || die "$SITE not found."
DEPLOY_USER="${SUDO_USER:-$(stat -c %U "$APP_ROOT")}"
PHP_BIN="$(ls /usr/bin/php[0-9]* 2>/dev/null | grep -E "php[0-9]+\.[0-9]+$" | sort -V | tail -1)"
[[ -n "$PHP_BIN" ]] || die "PHP not found."
web() { sudo -u www-data "$PHP_BIN" "$APP_ROOT/artisan" "$@"; }
env_get() { { grep -E "^$1=" "$2" 2>/dev/null || true; } | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

# -----------------------------------------------------------------------------
step "Checks"
web list --raw 2>/dev/null | grep -q '^reverb:start' || die "Live auction server package missing. Run: bash $APP_ROOT/deploy/deploy-website.sh first."
KEY_ID="$(env_get RAZORPAY_KEY_ID "$ENV_FILE")"
if [[ -z "$KEY_ID" || -z "$(env_get RAZORPAY_KEY_SECRET "$ENV_FILE")" ]]; then
  warn "Razorpay keys are empty: customers will see 'Online payment is being set up'. You can add them later."
elif [[ "$KEY_ID" == rzp_test_* ]]; then
  warn "Razorpay keys are TEST keys: no real money will be charged. Put the live keys in .env before customers pay."
else
  echo "    Razorpay live keys present"
fi
echo "    OK"

# -----------------------------------------------------------------------------
step "App settings (.env)"
cp -a "$ENV_FILE" "$ENV_FILE.bak-switch-$STAMP"
set_env() { # key value [only_if_empty]
  local cur; cur="$(env_get "$1" "$ENV_FILE")"
  [[ -n "${3:-}" && -n "$cur" ]] && return 0
  K="$1" V="$2" F="$ENV_FILE" "$PHP_BIN" -r '
    $f = getenv("F"); $k = getenv("K"); $line = $k."=".getenv("V");
    $lines = preg_split("/\R/", rtrim(file_get_contents($f), "\n"));
    $done = false;
    foreach ($lines as $i => $l) { if (preg_match("/^#?\s*".preg_quote($k, "/")."=/", $l)) { $lines[$i] = $line; $done = true; break; } }
    if (! $done) { $lines[] = $line; }
    file_put_contents($f, implode("\n", $lines)."\n");'
}
rand() { tr -dc "$1" </dev/urandom | head -c "$2" || true; }

# Live auction server port: localhost only, never 8090 (partner) or staging's port.
PORT="$(env_get REVERB_SERVER_PORT "$ENV_FILE")"
if [[ -z "$PORT" ]]; then
  PORT=8191
  while ss -ltnH "( sport = :$PORT )" | grep -q .; do PORT=$((PORT + 1)); done
fi

set_env SITE_MODE app
set_env QUEUE_CONNECTION database
set_env BROADCAST_CONNECTION reverb
set_env REVERB_APP_ID "$(rand '0-9' 6)" keep
set_env REVERB_APP_KEY "$(rand 'a-z0-9' 20)" keep
set_env REVERB_APP_SECRET "$(rand 'a-z0-9' 32)" keep
set_env REVERB_HOST "$HOST"
set_env REVERB_PORT 443
set_env REVERB_SCHEME https
set_env REVERB_SERVER_HOST 127.0.0.1
set_env REVERB_SERVER_PORT "$PORT"
set_env REVERB_INTERNAL_HOST 127.0.0.1
set_env REVERB_ALLOWED_ORIGINS "$HOST"
set_env REVERB_SCALING_ENABLED false   # one server, no Redis: nothing shared with other apps
set_env VITE_REVERB_APP_KEY '"${REVERB_APP_KEY}"'
set_env VITE_REVERB_HOST '"${REVERB_HOST}"'
set_env VITE_REVERB_PORT '"${REVERB_PORT}"'
set_env VITE_REVERB_SCHEME '"${REVERB_SCHEME}"'

# AI requirement reading: same key as staging, copied without printing it.
if [[ -z "$(env_get ANTHROPIC_API_KEY "$ENV_FILE")" && -f "$STAGING_ROOT/.env" ]]; then
  for k in ANTHROPIC_API_KEY ANTHROPIC_MODEL; do
    v="$(env_get "$k" "$STAGING_ROOT/.env")"; [[ -n "$v" ]] && set_env "$k" "$v"
  done
fi
[[ -n "$(env_get ANTHROPIC_API_KEY "$ENV_FILE")" ]] && echo "    AI key present" || warn "No AI key: 'Read with AI' stays off until ANTHROPIC_API_KEY is set."

# Razorpay webhook secret: made here once; you paste it into Razorpay (shown at the end).
NEW_WEBHOOK=""
if [[ -z "$(env_get RAZORPAY_WEBHOOK_SECRET "$ENV_FILE")" ]]; then
  set_env RAZORPAY_WEBHOOK_SECRET "$(rand 'A-Za-z0-9' 40)"
  NEW_WEBHOOK=1
fi
chown "$DEPLOY_USER":www-data "$ENV_FILE"; chmod 640 "$ENV_FILE"
echo "    Saved (backup: .env.bak-switch-$STAMP), live auction port $PORT"

# -----------------------------------------------------------------------------
step "Background worker, live auction server, scheduler"
cat > /etc/supervisor/conf.d/getl1-www-worker.conf <<EOF
[program:getl1-www-worker]
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
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
stopwaitsecs=3600
EOF
cat > /etc/supervisor/conf.d/getl1-www-reverb.conf <<EOF
[program:getl1-www-reverb]
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
echo "* * * * * www-data cd $APP_ROOT && $PHP_BIN artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/getl1-www
chmod 644 /etc/cron.d/getl1-www
echo "    supervisor: getl1-www-worker, getl1-www-reverb · cron: /etc/cron.d/getl1-www"

# -----------------------------------------------------------------------------
step "nginx: live auction connections on $HOST"
BACKUP="/root/getl1.com.nginx.bak-$STAMP"
cp -a "$SITE" "$BACKUP"
if grep -q "getl1-reverb" "$SITE"; then
  sed -i -E "/getl1-reverb/,/^    }/ s|proxy_pass http://127\.0\.0\.1:[0-9]+;|proxy_pass http://127.0.0.1:$PORT;|" "$SITE"
  echo "    Already present, port set to $PORT"
else
  BLOCK="$(mktemp)"
  cat > "$BLOCK" <<EOF
    # getl1-reverb: live auction websockets -> local server. Private channels are still
    # authorised per user by /broadcasting/auth behind the login.
    location ^~ /app/ {
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
  # Before the first "location / {" (certbot turns the first server block into the HTTPS one).
  awk -v blk="$BLOCK" '!done && /^[[:space:]]*location \/ \{/ { while ((getline l < blk) > 0) print l; done=1 } { print }' "$BACKUP" > "$SITE"
  rm -f "$BLOCK"
  grep -q "getl1-reverb" "$SITE" || { cp -a "$BACKUP" "$SITE"; die "Couldn't find the HTTPS 'location / {' in $SITE. Nothing changed in nginx."; }
  echo "    Added"
fi
if ! nginx -t 2>/tmp/getl1-www-nginx-test; then
  cat /tmp/getl1-www-nginx-test
  cp -a "$BACKUP" "$SITE"
  die "nginx test failed, restored the previous getl1.com config. Other sites were not touched."
fi
systemctl reload nginx
echo "    nginx reloaded (backup: $BACKUP)"

# -----------------------------------------------------------------------------
step "Build and start"
sudo -u "$DEPLOY_USER" -H bash -c "cd '$APP_ROOT' && npm run build >/dev/null"
web optimize:clear >/dev/null
web config:cache >/dev/null; web route:cache >/dev/null; web view:cache >/dev/null
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
supervisorctl restart getl1-www-worker >/dev/null 2>&1 || supervisorctl start getl1-www-worker >/dev/null
supervisorctl restart getl1-www-reverb >/dev/null 2>&1 || supervisorctl start getl1-www-reverb >/dev/null
sleep 3
supervisorctl status getl1-www-worker getl1-www-reverb || true

step "Health check"
web getl1:health || warn "Some checks need attention (see above). The scheduler check turns green within 2 minutes."

step "Done: getl1.com is now the full app"
echo "  Open https://$HOST/register to check sign-up works."
if [[ -n "$NEW_WEBHOOK" ]]; then
cat <<EOF

  Razorpay webhook (do this now, in Razorpay Live mode -> Account & Settings -> Webhooks -> Add new webhook):
    Webhook URL:    https://$HOST/webhooks/razorpay
    Secret:         $(env_get RAZORPAY_WEBHOOK_SECRET "$ENV_FILE")
    Alert email:    your email
    Active events:  payment.captured, payment.failed,
                    subscription.activated, subscription.charged, subscription.resumed,
                    subscription.pending, subscription.halted, subscription.cancelled, subscription.completed
  Copy the secret into Razorpay only. Don't paste it in chat or anywhere else.
EOF
fi
cat <<EOF

  Undo (back to website-only):
    sudo cp $ENV_FILE.bak-switch-$STAMP $ENV_FILE
    sudo cp $BACKUP $SITE && sudo systemctl reload nginx
    sudo supervisorctl stop getl1-www-worker getl1-www-reverb
    sudo rm /etc/supervisor/conf.d/getl1-www-worker.conf /etc/supervisor/conf.d/getl1-www-reverb.conf /etc/cron.d/getl1-www && sudo supervisorctl update
    cd $APP_ROOT && sudo -u www-data php artisan config:cache
EOF
