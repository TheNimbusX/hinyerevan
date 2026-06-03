#!/bin/bash
# Waits until no AAAA on apex/www, then issues cert and updates .env
set -euo pipefail
DOMAIN=hinyerevan.ru
ENV=/var/www/hinyerevan/backend/.env

for i in $(seq 1 60); do
  a=$(dig +short "$DOMAIN" AAAA @ns1.hosting.reg.ru | head -1)
  w=$(dig +short "www.$DOMAIN" AAAA @ns1.hosting.reg.ru | head -1)
  echo "$(date -Is) try $i apex_AAAA=[$a] www_AAAA=[$w]"
  if [ -z "$a" ] && [ -z "$w" ]; then
    echo "No AAAA — requesting certificate..."
    certbot --nginx -d "$DOMAIN" -d "www.$DOMAIN" \
      --non-interactive --agree-tos -m "admin@$DOMAIN" --redirect && break
  fi
  sleep 60
done

if ! [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
  echo "FAILED: remove AAAA for hinyerevan.ru and www in reg.ru DNS"
  exit 1
fi

set_env() { grep -q "^${1}=" "$ENV" && sed -i "s|^${1}=.*|${1}=${2}|" "$ENV" || echo "${1}=${2}" >> "$ENV"; }
BASE="https://${DOMAIN}"
set_env OAUTH_REDIRECT_BASE "$BASE"
set_env FRONTEND_URL "$BASE"
set_env APP_URL "$BASE"
cd /var/www/hinyerevan/backend && php artisan config:cache
echo "OK https://$DOMAIN"
