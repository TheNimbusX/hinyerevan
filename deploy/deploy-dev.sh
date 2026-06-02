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
cd ../frontend
npm ci --silent 2>/dev/null || npm install --silent
npm run build --silent
echo "DEPLOYED: $(git -C /var/www/hinyerevan log -1 --oneline)"
