#!/bin/bash
# Pull a fresh MySQL dump from legacy hinyerevan.com server and import into dev VPS DB.
# Requires /root/.hinyerevan-secrets.env (chmod 600). Run on VPS only.
set -euo pipefail

SECRETS_FILE="${SECRETS_FILE:-/root/.hinyerevan-secrets.env}"
APP_DIR=/var/www/hinyerevan/backend
DUMP_PATH=/root/live-hinyerevan.sql.gz
STAMP=$(date -u +%Y%m%d-%H%M%S)
BACKUP_PATH="/root/hin_yerevan-before-live-${STAMP}.sql.gz"

if [[ ! -f "$SECRETS_FILE" ]]; then
  echo "Missing $SECRETS_FILE" >&2
  exit 1
fi

# shellcheck disable=SC1090
source "$SECRETS_FILE"

: "${OLD_SSH_HOST:?}"
: "${OLD_SSH_USER:?}"
: "${OLD_SSH_PASS:?}"
: "${OLD_DB_HOST:=127.0.0.1}"
: "${OLD_DB_PORT:=3310}"
: "${OLD_DB_USER:?}"
: "${OLD_DB_PASS:?}"
: "${OLD_DB_NAME:?}"
: "${VPS_DB_NAME:=hin_yerevan}"

apt-get install -y sshpass gzip >/dev/null 2>&1 || true

echo "==> Backup current dev database to $BACKUP_PATH"
mysqldump --single-transaction --quick --routines --triggers "$VPS_DB_NAME" | gzip -c > "$BACKUP_PATH"

echo "==> Dump live database from $OLD_SSH_HOST"
export SSHPASS="$OLD_SSH_PASS"
sshpass -e ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 \
  "${OLD_SSH_USER}@${OLD_SSH_HOST}" \
  "mysqldump --column-statistics=0 --no-tablespaces --single-transaction --quick --routines --triggers \
    -h '${OLD_DB_HOST}' -P '${OLD_DB_PORT}' -u '${OLD_DB_USER}' -p'${OLD_DB_PASS}' '${OLD_DB_NAME}'" \
  | gzip -c > "$DUMP_PATH"

echo "==> Verify dump archive"
gzip -t "$DUMP_PATH"
ls -lh "$DUMP_PATH"

echo "==> Import into $VPS_DB_NAME"
mysql -e "SET SESSION sql_mode = REPLACE(REPLACE(REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', ''), 'STRICT_TRANS_TABLES', '');"
mysql -e "DROP DATABASE IF EXISTS \`${VPS_DB_NAME}\`; CREATE DATABASE \`${VPS_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c "$DUMP_PATH" | mysql "$VPS_DB_NAME"

echo "==> Bootstrap Laravel migrations on top of legacy dump"
cd "$APP_DIR"
sed -i "s/'strict' => true/'strict' => false/" config/database.php
php artisan migrate:install 2>/dev/null || true
mysql "$VPS_DB_NAME" <<'SQL'
INSERT IGNORE INTO migrations (migration, batch) VALUES
('2014_10_12_000000_create_users_table', 1);
SQL
php artisan migrate --force 2>/dev/null || true

echo "==> Re-apply additive schema (handles legacy zero-dates)"
php artisan legacy:repair-schema
php artisan config:cache
php artisan route:cache
rm -rf storage/app/watermarked/* storage/framework/cache/data/* 2>/dev/null || true
chown -R www-data:www-data storage bootstrap/cache

echo "==> Row counts"
mysql -N -e "
  SELECT 'photos', COUNT(*) FROM ${VPS_DB_NAME}.photos
  UNION ALL SELECT 'users', COUNT(*) FROM ${VPS_DB_NAME}.users
  UNION ALL SELECT 'comments', COUNT(*) FROM ${VPS_DB_NAME}.comments;
"

echo "Live import complete. Backup of previous dev DB: $BACKUP_PATH"
