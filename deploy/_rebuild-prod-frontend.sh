#!/bin/bash
set -euo pipefail
cd /var/www/hinyerevan/backend
# PHP runs in a container on the Dubai VM.
if docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^hinyerevan-php$'; then
  docker exec -u www-data -w /var/www/hinyerevan/backend hinyerevan-php php artisan config:cache
  docker exec -u www-data -w /var/www/hinyerevan/backend hinyerevan-php php artisan route:cache
else
  php artisan config:cache
fi
cd ../frontend
RECAPTCHA_SITE_KEY=$(grep -m1 '^RECAPTCHA_SITE_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
YANDEX_MAPS_KEY=$(grep -m1 '^YANDEX_MAPS_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
GOOGLE_MAPS_KEY=$(grep -m1 '^GOOGLE_MAPS_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
FACEBOOK_APP_ID=$(grep -m1 '^FACEBOOK_PLUGIN_APP_ID=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
{
  # Relative base: works on any host (IP, domain, dev stand).
  printf 'VITE_API_URL=%s\n' '/api'
  [ -n "$RECAPTCHA_SITE_KEY" ] && printf 'VITE_RECAPTCHA_SITE_KEY=%s\n' "$RECAPTCHA_SITE_KEY"
  [ -n "$YANDEX_MAPS_KEY" ] && printf 'VITE_YANDEX_MAPS_KEY=%s\n' "$YANDEX_MAPS_KEY"
  [ -n "$GOOGLE_MAPS_KEY" ] && printf 'VITE_GOOGLE_MAPS_KEY=%s\n' "$GOOGLE_MAPS_KEY"
  printf 'VITE_SITE_URL=%s\n' 'https://hinyerevan.com'
  [ -n "$FACEBOOK_APP_ID" ] && printf 'VITE_FACEBOOK_APP_ID=%s\n' "$FACEBOOK_APP_ID"
} > .env
npm run build --silent
curl -s https://hinyerevan.com/api/dev-auth/status
echo
curl -s https://dev.hinyerevan.com/api/dev-auth/status
echo
