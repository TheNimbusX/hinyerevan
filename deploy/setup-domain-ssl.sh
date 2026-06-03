#!/bin/bash
# Usage: bash setup-domain-ssl.sh hinyerevan.ru
set -euo pipefail
DOMAIN="${1:?Usage: $0 domain.ru}"
ENV=/var/www/hinyerevan/backend/.env
NGINX=/etc/nginx/sites-available/hinyerevan
EXPECTED_IP="45.138.25.76"

echo "=== DNS check $DOMAIN ==="
IP=$(dig +short "$DOMAIN" A @8.8.8.8 | tail -1)
if [ "$IP" != "$EXPECTED_IP" ]; then
  echo "ERROR: $DOMAIN -> '${IP:-NXDOMAIN}', need $EXPECTED_IP"
  exit 1
fi
echo "DNS OK: $DOMAIN -> $IP"

AAAA=$(dig +short "$DOMAIN" AAAA @ns1.hosting.reg.ru | head -1)
if [ -n "$AAAA" ]; then
  echo "WARN: AAAA still on reg.ru DNS: $AAAA"
  echo "Remove AAAA for hinyerevan.ru and www (not only ftp/mail), save zone."
fi

if ! grep -q "$DOMAIN" "$NGINX"; then
  sed -i "s/server_name .*/server_name $DOMAIN www.$DOMAIN 45.138.25.76 _;/" "$NGINX"
  nginx -t && systemctl reload nginx
fi

command -v certbot >/dev/null || apt-get install -y certbot python3-certbot-nginx

certbot --nginx -d "$DOMAIN" -d "www.$DOMAIN" \
  --non-interactive --agree-tos -m "admin@${DOMAIN}" --redirect 2>/dev/null || \
certbot --nginx -d "$DOMAIN" -d "www.$DOMAIN" \
  --non-interactive --agree-tos --register-unsafely-without-email --redirect

set_env() {
  local key="$1" val="$2"
  if grep -q "^${key}=" "$ENV"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$ENV"
  else
    echo "${key}=${val}" >> "$ENV"
  fi
}

BASE="https://${DOMAIN}"
set_env OAUTH_REDIRECT_BASE "$BASE"
set_env FRONTEND_URL "$BASE"
set_env APP_URL "$BASE"

cd /var/www/hinyerevan/backend
php artisan config:cache
php artisan route:cache

echo ""
echo "=== DONE ==="
echo "Site:    $BASE"
echo "VK:      $BASE/api/auth/social/vkontakte/callback"
echo "Yandex:  $BASE/api/auth/social/yandex/callback"
echo "Google:  $BASE/api/auth/social/google/callback"
