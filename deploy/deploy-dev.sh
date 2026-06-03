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
cd ../frontend
npm ci --silent 2>/dev/null || npm install --silent
npm run build --silent
echo "DEPLOYED: $(git -C /var/www/hinyerevan log -1 --oneline)"
