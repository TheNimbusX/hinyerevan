#!/bin/bash
# Update production Facebook Page integration (.env on VPS).
# Set PAGE_TOKEN before running (Page token from GET /me/accounts, not User token).
set -euo pipefail
: "${PAGE_TOKEN:?Set PAGE_TOKEN to the Page access_token from /me/accounts}"
: "${APP_SECRET:?Set APP_SECRET to the Meta app secret}"

ENV=/var/www/hinyerevan/backend/.env
APP_ID="${APP_ID:-1502109724726341}"
PAGE_ID=134129376737442
PAGE_URL=https://www.facebook.com/HinYerevanCom/

replace() {
  local key="$1" val="$2"
  if grep -q "^${key}=" "$ENV"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$ENV"
  else
    echo "${key}=${val}" >> "$ENV"
  fi
}

replace FACEBOOK_APP_ID "$APP_ID"
replace FACEBOOK_APP_SECRET "$APP_SECRET"
replace FACEBOOK_PLUGIN_APP_ID "$APP_ID"
replace FACEBOOK_PAGE_ID "$PAGE_ID"
replace FACEBOOK_PAGE_URL "$PAGE_URL"
replace FACEBOOK_PAGE_ACCESS_TOKEN "$PAGE_TOKEN"

cd /var/www/hinyerevan/backend
php artisan config:clear
php artisan facebook:exchange-token "$PAGE_TOKEN" --write-env
php artisan config:cache
php artisan facebook:diagnose

cd /var/www/hinyerevan/frontend
RECAPTCHA_SITE_KEY=$(grep -m1 '^RECAPTCHA_SITE_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
YANDEX_MAPS_KEY=$(grep -m1 '^YANDEX_MAPS_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
GOOGLE_MAPS_KEY=$(grep -m1 '^GOOGLE_MAPS_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
SITE_URL=$(grep -m1 '^FRONTEND_URL=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
{
  [ -n "$RECAPTCHA_SITE_KEY" ] && printf 'VITE_RECAPTCHA_SITE_KEY=%s\n' "$RECAPTCHA_SITE_KEY"
  [ -n "$YANDEX_MAPS_KEY" ] && printf 'VITE_YANDEX_MAPS_KEY=%s\n' "$YANDEX_MAPS_KEY"
  [ -n "$GOOGLE_MAPS_KEY" ] && printf 'VITE_GOOGLE_MAPS_KEY=%s\n' "$GOOGLE_MAPS_KEY"
  [ -n "$SITE_URL" ] && printf 'VITE_SITE_URL=%s\n' "$SITE_URL"
  printf 'VITE_FACEBOOK_APP_ID=%s\n' "$APP_ID"
} > .env
npm run build --silent

echo "DONE: Facebook app $APP_ID + page $PAGE_ID"
