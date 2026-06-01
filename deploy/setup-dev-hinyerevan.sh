#!/bin/bash
set -euo pipefail
DOMAIN=dev.hinyerevan.com
ENV=/var/www/hinyerevan/backend/.env
NGINX=/etc/nginx/sites-available/hinyerevan

echo "Waiting for DNS $DOMAIN -> 45.138.25.76 ..."
for i in $(seq 1 30); do
  IP=$(dig +short "$DOMAIN" A @8.8.8.8 2>/dev/null | tail -1)
  if [ "$IP" = "45.138.25.76" ]; then
    echo "DNS OK"
    break
  fi
  echo "  attempt $i: got '${IP:-none}'"
  sleep 10
done

IP=$(dig +short "$DOMAIN" A @8.8.8.8 2>/dev/null | tail -1)
if [ "$IP" != "45.138.25.76" ]; then
  echo "WARN: DNS not propagated yet; continuing anyway"
fi

# nginx: server_name for certbot
if ! grep -q "$DOMAIN" "$NGINX"; then
  sed -i "s/server_name _;/server_name $DOMAIN _;/" "$NGINX"
  nginx -t && systemctl reload nginx
fi

apt-get update -qq
apt-get install -y certbot python3-certbot-nginx

certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m admin@hinyerevan.com --redirect || \
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect

set_env() {
  local key="$1" val="$2"
  if grep -q "^${key}=" "$ENV"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$ENV"
  else
    echo "${key}=${val}" >> "$ENV"
  fi
}

set_env OAUTH_REDIRECT_BASE "https://${DOMAIN}"
set_env FRONTEND_URL "https://${DOMAIN}"
set_env APP_URL "https://${DOMAIN}"

cd /var/www/hinyerevan/backend
php artisan config:cache
php artisan route:cache

echo ""
echo "=== DONE ==="
echo "Site: https://${DOMAIN}"
echo "VK redirect: https://${DOMAIN}/api/auth/social/vkontakte/callback"
grep -E '^(OAUTH_REDIRECT_BASE|FRONTEND_URL|APP_URL)=' "$ENV"
