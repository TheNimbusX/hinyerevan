#!/bin/bash
# Run on dev VPS after dev.hinyerevan.com A-record is live on reg.ru DNS.
set -euo pipefail
DOMAIN=dev.hinyerevan.com
ENV=/var/www/hinyerevan/backend/.env

IP=$(dig +short "$DOMAIN" A @8.8.8.8 | tail -1)
if [ "$IP" != "45.138.25.76" ]; then
  echo "ERROR: $DOMAIN resolves to '${IP:-NXDOMAIN}', expected 45.138.25.76"
  echo "Add A record in reg.ru: dev -> 45.138.25.76 (see deploy/REG-DNS-DEV.md)"
  exit 1
fi

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

cd /var/www/hinyerevan/backend && php artisan config:cache

echo "OK: https://${DOMAIN}"
echo "VK: https://${DOMAIN}/api/auth/social/vkontakte/callback"
