#!/bin/bash
# HinYerevan backups on the Dubai VM (MySQL runs in the mysql-server container).
#   MODE=db    (default) database + config, a few MB
#   MODE=full  also mirrors all photos + storage/app to Yandex.Disk
# Offsite: rclone remote "yadisk", same layout as before (yadisk:hinyerevan/...).
set -euo pipefail

APP=/var/www/hinyerevan
BACKUP_DIR=${BACKUP_DIR:-/root/backups}
MODE=${MODE:-db}
KEEP=${KEEP:-14}
OFFSITE=${OFFSITE:-yadisk:hinyerevan}
STAMP=$(date +%Y%m%d-%H%M%S)
DB_NAME=$(grep -m1 '^DB_DATABASE=' "$APP/backend/.env" | cut -d= -f2- | tr -d '\r"')
MYSQL_ROOT_PW=$(cat /root/mysql_root_password.txt)

mkdir -p "$BACKUP_DIR"
echo "==> [$STAMP] mode=$MODE"

DUMP="$BACKUP_DIR/$DB_NAME-$STAMP.sql.gz"
docker exec mysql-server mysqldump -uroot -p"$MYSQL_ROOT_PW" \
  --single-transaction --quick --routines --events --default-character-set=utf8mb4 \
  "$DB_NAME" 2>/dev/null | gzip -c > "$DUMP"
ls -lh "$DUMP"

CONF_DIR="$BACKUP_DIR/config-latest"
mkdir -p "$CONF_DIR"
cp -f "$APP/backend/.env" "$CONF_DIR/backend.env"
cp -f /etc/nginx/sites-available/hinyerevan "$CONF_DIR/hinyerevan.nginx"
cp -f /etc/cron.d/hinyerevan-scheduler "$CONF_DIR/cron-scheduler"
cp -f /etc/cron.d/hinyerevan-backup "$CONF_DIR/cron-backup"
cp -f /root/hy-backup.sh "$CONF_DIR/hy-backup.sh"
cp -f /root/php-image/Dockerfile "$CONF_DIR/php.Dockerfile"
git -C "$APP" rev-parse HEAD > "$CONF_DIR/git-commit.txt" 2>/dev/null || true

# local rotation
ls -1t "$BACKUP_DIR/$DB_NAME"-*.sql.gz 2>/dev/null | tail -n +$((KEEP+1)) | xargs -r rm -f

if rclone listremotes 2>/dev/null | grep -q "^${OFFSITE%%:*}:"; then
  echo "==> offsite -> $OFFSITE"
  rclone copyto "$DUMP" "$OFFSITE/db/$(basename "$DUMP")" || echo "WARN: db upload failed" >&2
  rclone copy "$CONF_DIR" "$OFFSITE/config" || echo "WARN: config upload failed" >&2
  # keep newest $KEEP dumps offsite
  rclone lsf "$OFFSITE/db" --files-only 2>/dev/null | sort -r | tail -n +$((KEEP+1)) \
    | while read -r f; do rclone deletefile "$OFFSITE/db/$f" || true; done

  if [ "$MODE" = "full" ]; then
    echo "==> photos -> $OFFSITE/legacy"
    # Photo names are unique and immutable: size check avoids 34k modtime API calls.
    rclone sync "$APP/legacy" "$OFFSITE/legacy" --size-only --transfers 4 --checkers 8 --fast-list \
      || echo "WARN: photo sync failed" >&2
    echo "==> storage/app -> $OFFSITE/storage-app"
    rclone sync "$APP/backend/storage/app" "$OFFSITE/storage-app" --transfers 4 --checkers 8 \
      --exclude 'watermarked/**' --exclude 'cache/**' || echo "WARN: storage sync failed" >&2
  fi
else
  echo "WARN: rclone remote not configured, local backup only" >&2
fi

df -h / | tail -1
echo "==> backup done"
