#!/usr/bin/env bash
# =============================================================================
# GetL1 behind Cloudflare: make nginx (and so Laravel's rate limits, login lockouts and
# security logs) see each visitor's real IP instead of Cloudflare's.
#
# Only the GetL1 nginx sites (getl1.com, staging.getl1.com) get the include; other sites on
# this server are not touched. Files are backed up and restored if nginx -t fails.
# Safe to re-run (refreshes Cloudflare's IP list). Run once BEFORE turning on the orange cloud.
#
#   sudo bash /cdata/getl1-staging/deploy/cloudflare-realip.sh
# =============================================================================
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run with sudo"; exit 1; }

SNIPPET=/etc/nginx/snippets/getl1-cloudflare-realip.conf
SITES=(/etc/nginx/sites-available/getl1.com /etc/nginx/sites-available/staging.getl1.com)
BACKUP_DIR=/root/getl1-backups; STAMP="$(date +%Y%m%d-%H%M%S)"
install -d -m 700 "$BACKUP_DIR"; install -d /etc/nginx/snippets

# Cloudflare's published ranges (fallback list if the download fails).
V4="$(curl -fsS --max-time 10 https://www.cloudflare.com/ips-v4 || true)"
V6="$(curl -fsS --max-time 10 https://www.cloudflare.com/ips-v6 || true)"
if ! grep -qE '^[0-9]+\.' <<<"$V4"; then
  echo "[!] Couldn't download Cloudflare's list; using the built-in one."
  V4=$'173.245.48.0/20\n103.21.244.0/22\n103.22.200.0/22\n103.31.4.0/22\n141.101.64.0/18\n108.162.192.0/18\n190.93.240.0/20\n188.114.96.0/20\n197.234.240.0/22\n198.41.128.0/17\n162.158.0.0/15\n104.16.0.0/13\n104.24.0.0/14\n172.64.0.0/13\n131.0.72.0/22'
  V6=$'2400:cb00::/32\n2606:4700::/32\n2803:f800::/32\n2405:b500::/32\n2405:8100::/32\n2a06:98c0::/29\n2c0f:f248::/32'
fi

TMP="$(mktemp)"
{
  echo "# Managed by GetL1 deploy/cloudflare-realip.sh ($STAMP). Real visitor IP from Cloudflare."
  while read -r ip; do [[ -n "$ip" ]] && echo "set_real_ip_from $ip;"; done <<<"$V4"$'\n'"$V6"
  echo "real_ip_header CF-Connecting-IP;"
  echo "real_ip_recursive on;"
} > "$TMP"
[[ -f "$SNIPPET" ]] && cp -a "$SNIPPET" "$BACKUP_DIR/getl1-cloudflare-realip.$STAMP.conf"
install -m 644 "$TMP" "$SNIPPET"; rm -f "$TMP"
echo "==> $(grep -c set_real_ip_from "$SNIPPET") Cloudflare ranges written to $SNIPPET"

CHANGED=()
for f in "${SITES[@]}"; do
  [[ -f "$f" ]] || { echo "    skip (not found): $f"; continue; }
  if grep -q "getl1-cloudflare-realip.conf" "$f"; then echo "    already included: $f"; continue; fi
  cp -a "$f" "$BACKUP_DIR/$(basename "$f").$STAMP.conf"
  # After every server_name line (covers the HTTP and HTTPS server blocks certbot keeps).
  sed -i -E "s|^([[:space:]]*server_name[^;]*;)|\1\n    include $SNIPPET;|" "$f"
  CHANGED+=("$f")
  echo "    included in: $f"
done

if ! nginx -t 2>/tmp/getl1-nginx-cf; then
  cat /tmp/getl1-nginx-cf
  for f in "${CHANGED[@]}"; do cp -a "$BACKUP_DIR/$(basename "$f").$STAMP.conf" "$f"; done
  echo "[x] nginx test failed; GetL1 site files restored. Other sites untouched."; exit 1
fi
systemctl reload nginx
echo "==> Done. nginx reloaded. Turn on the orange cloud in Cloudflare now."
