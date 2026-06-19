#!/bin/bash
set -euo pipefail
cd /var/www/hinyerevan/backend
php artisan config:cache
cd ../frontend
RECAPTCHA_SITE_KEY=$(grep -m1 '^RECAPTCHA_SITE_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
YANDEX_MAPS_KEY=$(grep -m1 '^YANDEX_MAPS_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
GOOGLE_MAPS_KEY=$(grep -m1 '^GOOGLE_MAPS_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
FACEBOOK_APP_ID=$(grep -m1 '^FACEBOOK_PLUGIN_APP_ID=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
{
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
