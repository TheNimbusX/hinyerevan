#!/bin/bash
set -euo pipefail
DOMAIN=hinyerevan.ru
EXPECTED=45.138.25.76
ENV=/var/www/hinyerevan/backend/.env
NGINX=/etc/nginx/sites-available/hinyerevan

for i in $(seq 1 24); do
  IP=$(dig +short "$DOMAIN" A @ns1.hosting.reg.ru | tr '\n' ' ')
  echo "check $i: $DOMAIN -> [$IP]"
  if echo "$IP" | grep -q "$EXPECTED" && ! echo "$IP" | grep -q '31.31.196.205'; then
    break
  fi
  sleep 30
done

IP=$(dig +short "$DOMAIN" A @8.8.8.8 | head -1)
if [ "$IP" != "$EXPECTED" ]; then
  echo "WARN: 8.8.8.8 -> ${IP:-none}, continuing if ns1 OK"
fi

sed -i "s/server_name .*/server_name $DOMAIN www.$DOMAIN 45.138.25.76 _;/" "$NGINX"
nginx -t && systemctl reload nginx

command -v certbot >/dev/null || apt-get install -y -qq certbot python3-certbot-nginx

certbot --nginx -d "$DOMAIN" -d "www.$DOMAIN" \
  --non-interactive --agree-tos -m "admin@hinyerevan.com" --redirect

set_env() {
  grep -q "^${1}=" "$ENV" && sed -i "s|^${1}=.*|${1}=${2}|" "$ENV" || echo "${1}=${2}" >> "$ENV"
}
BASE="https://${DOMAIN}"
set_env OAUTH_REDIRECT_BASE "$BASE"
set_env FRONTEND_URL "$BASE"
set_env APP_URL "$BASE"

cd /var/www/hinyerevan/backend && php artisan config:cache && php artisan route:cache

echo "DONE $BASE"
echo "VK $BASE/api/auth/social/vkontakte/callback"
