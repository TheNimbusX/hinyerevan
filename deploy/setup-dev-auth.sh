#!/bin/bash
# Enable dev stand gate (modal + captcha) for dev.hinyerevan.com.
# Run on VPS: bash /var/www/hinyerevan/deploy/setup-dev-auth.sh
set -euo pipefail

DEV_USER="${DEV_HTTP_USER:-admin}"
DEV_PASS="${DEV_HTTP_PASS:-merp2026WEB}"
ENV=/var/www/hinyerevan/backend/.env

replace() {
  local key="$1" val="$2"
  if grep -q "^${key}=" "$ENV"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$ENV"
  else
    echo "${key}=${val}" >> "$ENV"
  fi
}

replace DEV_AUTH_ENABLED true
replace DEV_AUTH_USER "$DEV_USER"
replace DEV_AUTH_PASSWORD "$DEV_PASS"

cp /var/www/hinyerevan/deploy/nginx-hinyerevan.conf /etc/nginx/sites-available/hinyerevan
nginx -t
systemctl reload nginx

cd /var/www/hinyerevan/backend
php artisan config:cache

cd ../frontend
RECAPTCHA_SITE_KEY=$(grep -m1 '^RECAPTCHA_SITE_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
YANDEX_MAPS_KEY=$(grep -m1 '^YANDEX_MAPS_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
GOOGLE_MAPS_KEY=$(grep -m1 '^GOOGLE_MAPS_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
SITE_URL=$(grep -m1 '^FRONTEND_URL=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
FACEBOOK_APP_ID=$(grep -m1 '^FACEBOOK_APP_ID=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
{
  [ -n "$RECAPTCHA_SITE_KEY" ] && printf 'VITE_RECAPTCHA_SITE_KEY=%s\n' "$RECAPTCHA_SITE_KEY"
  [ -n "$YANDEX_MAPS_KEY" ] && printf 'VITE_YANDEX_MAPS_KEY=%s\n' "$YANDEX_MAPS_KEY"
  [ -n "$GOOGLE_MAPS_KEY" ] && printf 'VITE_GOOGLE_MAPS_KEY=%s\n' "$GOOGLE_MAPS_KEY"
  [ -n "$SITE_URL" ] && printf 'VITE_SITE_URL=%s\n' "$SITE_URL"
  [ -n "$FACEBOOK_APP_ID" ] && printf 'VITE_FACEBOOK_APP_ID=%s\n' "$FACEBOOK_APP_ID"
  printf 'VITE_DEV_AUTH_REQUIRED=true\n'
} > .env
npm run build --silent

echo "Dev auth enabled (user: $DEV_USER) — captcha modal on dev.hinyerevan.com"
