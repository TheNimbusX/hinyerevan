#!/bin/bash
# Install the Laravel scheduler cron for HinYerevan.
# Without this, schedule:run never fires, so Facebook stats/likes/views/comments
# only sync when a photo page is opened. Run once on the VPS (idempotent):
#   bash /var/www/hinyerevan/deploy/setup-cron.sh
set -euo pipefail

APP_DIR=/var/www/hinyerevan/backend
PHP_BIN=$(command -v php || echo /usr/bin/php)
LOG_FILE=/var/log/hinyerevan-schedule.log
CRON_FILE=/etc/cron.d/hinyerevan-scheduler

touch "$LOG_FILE"
chown www-data:www-data "$LOG_FILE"

cat > "$CRON_FILE" <<EOF
# HinYerevan Laravel scheduler — runs every minute, dispatches due jobs
# (Facebook post-stats sync runs everyFiveMinutes via Console/Kernel.php).
* * * * * www-data cd $APP_DIR && $PHP_BIN artisan schedule:run >> $LOG_FILE 2>&1
EOF
chmod 644 "$CRON_FILE"

# Reload cron so the new file is picked up immediately.
service cron reload 2>/dev/null || systemctl reload cron 2>/dev/null || true

echo "Installed $CRON_FILE:"
cat "$CRON_FILE"
echo "Scheduler will run every minute as www-data; log: $LOG_FILE"
