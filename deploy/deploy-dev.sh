#!/bin/bash
set -euo pipefail
cd /var/www/hinyerevan
git fetch origin dev
git reset --hard FETCH_HEAD
cd backend
composer install --no-dev --optimize-autoloader 2>/dev/null || composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan migrate --force
# Storage + bootstrap cache must stay writable by the web user. chown (not delete)
# so root-owned cache files become writable WITHOUT wiping the translation cache.
chown -R www-data:www-data storage bootstrap/cache || true
find storage -type d -exec chmod 775 {} \; || true
# .env must be writable by the scheduler user so facebook:refresh-token can
# persist the rotated Page token.
chown www-data:www-data .env || true
cd ../frontend
# Bake public frontend keys from backend .env.
RECAPTCHA_SITE_KEY=$(grep -m1 '^RECAPTCHA_SITE_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
YANDEX_MAPS_KEY=$(grep -m1 '^YANDEX_MAPS_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
GOOGLE_MAPS_KEY=$(grep -m1 '^GOOGLE_MAPS_KEY=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
{
  [ -n "$RECAPTCHA_SITE_KEY" ] && printf 'VITE_RECAPTCHA_SITE_KEY=%s\n' "$RECAPTCHA_SITE_KEY"
  [ -n "$YANDEX_MAPS_KEY" ] && printf 'VITE_YANDEX_MAPS_KEY=%s\n' "$YANDEX_MAPS_KEY"
  [ -n "$GOOGLE_MAPS_KEY" ] && printf 'VITE_GOOGLE_MAPS_KEY=%s\n' "$GOOGLE_MAPS_KEY"
} > .env
npm ci --silent 2>/dev/null || npm install --silent
npm run build --silent
echo "DEPLOYED: $(git -C /var/www/hinyerevan log -1 --oneline)"
