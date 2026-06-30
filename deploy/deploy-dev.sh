#!/bin/bash
set -euo pipefail
cd /var/www/hinyerevan
# Do NOT use git reset --hard — it wipes hotfixes uploaded via _sync-local-to-vps.ps1.
# Commit and push local changes first, then deploy; or use deploy/_sync-local-to-vps.ps1.
git fetch origin dev
if ! git diff --quiet || ! git diff --cached --quiet || [ -n "$(git status --porcelain)" ]; then
  echo "WARN: stashing server local changes before deploy (recover with git stash list)."
  git stash push -u -m "pre-deploy-$(date +%F-%T)" || true
fi
git merge --ff-only FETCH_HEAD
cd backend
composer install --no-dev --optimize-autoloader 2>/dev/null || composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan legacy:repair-schema
php artisan legacy:repair-text
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
SITE_URL=$(grep -m1 '^FRONTEND_URL=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
[ -z "$SITE_URL" ] && SITE_URL=$(grep -m1 '^APP_URL=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
FACEBOOK_APP_ID=$(grep -m1 '^FACEBOOK_APP_ID=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
DEV_AUTH_ENABLED=$(grep -m1 '^DEV_AUTH_ENABLED=' ../backend/.env | cut -d= -f2- | tr -d '\r"' || true)
php ../deploy/generate-og-share.php || true
{
  [ -n "$RECAPTCHA_SITE_KEY" ] && printf 'VITE_RECAPTCHA_SITE_KEY=%s\n' "$RECAPTCHA_SITE_KEY"
  [ -n "$YANDEX_MAPS_KEY" ] && printf 'VITE_YANDEX_MAPS_KEY=%s\n' "$YANDEX_MAPS_KEY"
  [ -n "$GOOGLE_MAPS_KEY" ] && printf 'VITE_GOOGLE_MAPS_KEY=%s\n' "$GOOGLE_MAPS_KEY"
  [ -n "$SITE_URL" ] && printf 'VITE_SITE_URL=%s\n' "$SITE_URL"
  [ -n "$FACEBOOK_APP_ID" ] && printf 'VITE_FACEBOOK_APP_ID=%s\n' "$FACEBOOK_APP_ID"
  [ "$DEV_AUTH_ENABLED" = "true" ] && printf 'VITE_DEV_AUTH_REQUIRED=true\n'
} > .env
npm ci --silent 2>/dev/null || npm install --silent
npm run build --silent
echo "DEPLOYED: $(git -C /var/www/hinyerevan log -1 --oneline)"
