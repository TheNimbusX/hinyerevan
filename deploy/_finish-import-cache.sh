#!/bin/bash
set -euo pipefail
APP_DIR=/var/www/hinyerevan/backend
DB_CFG="$APP_DIR/config/database.php"

sed -i "s/'strict' => true/'strict' => false/" "$DB_CFG"

cd "$APP_DIR"
php artisan legacy:repair-schema

php artisan optimize:clear
rm -rf storage/app/watermarked/* storage/framework/cache/data/* 2>/dev/null || true
php artisan config:cache
php artisan route:cache
chown -R www-data:www-data storage bootstrap/cache

mysql -N hin_yerevan -e "SELECT 'photos', COUNT(*) FROM photos UNION ALL SELECT 'users', COUNT(*) FROM users UNION ALL SELECT 'comments', COUNT(*) FROM comments;"
