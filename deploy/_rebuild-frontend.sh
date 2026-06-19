#!/bin/bash
set -euo pipefail
cd /var/www/hinyerevan/backend
php artisan route:clear
php artisan config:cache
cd ../frontend
RECAPTCHA_SITE_KEY=$(grep -m1 '^RECAPTCHA_SITE_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
YANDEX_MAPS_KEY=$(grep -m1 '^YANDEX_MAPS_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
GOOGLE_MAPS_KEY=$(grep -m1 '^GOOGLE_MAPS_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
SITE_URL=$(grep -m1 '^FRONTEND_URL=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
FACEBOOK_APP_ID=$(grep -m1 '^FACEBOOK_APP_ID=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
DEV_AUTH_ENABLED=$(grep -m1 '^DEV_AUTH_ENABLED=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
{
  [ -n "$RECAPTCHA_SITE_KEY" ] && printf 'VITE_RECAPTCHA_SITE_KEY=%s\n' "$RECAPTCHA_SITE_KEY"
  [ -n "$YANDEX_MAPS_KEY" ] && printf 'VITE_YANDEX_MAPS_KEY=%s\n' "$YANDEX_MAPS_KEY"
  [ -n "$GOOGLE_MAPS_KEY" ] && printf 'VITE_GOOGLE_MAPS_KEY=%s\n' "$GOOGLE_MAPS_KEY"
  [ -n "$SITE_URL" ] && printf 'VITE_SITE_URL=%s\n' "$SITE_URL"
  [ -n "$FACEBOOK_APP_ID" ] && printf 'VITE_FACEBOOK_APP_ID=%s\n' "$FACEBOOK_APP_ID"
  [ "$DEV_AUTH_ENABLED" = "true" ] && printf 'VITE_DEV_AUTH_REQUIRED=true\n'
} > .env
npm run build --silent
echo "REBUILD OK"
