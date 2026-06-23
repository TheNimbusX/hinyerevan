#!/bin/bash
# ============================================================================
# Install a daily automated backup for HinYerevan.
#
# Runs deploy/backup.sh once a day, keeps the newest KEEP archives in
# /root/backups, and (optionally) pushes the archive offsite.
#
# Run once on the VPS (idempotent):
#   bash /var/www/hinyerevan/deploy/setup-backup-cron.sh
#
# Env overrides:
#   HOUR=3                       hour of day (server time) to run, default 03:00
#   KEEP=7                       archives to keep
#   OFFSITE_SCP=user@host:/dir   optional: scp each archive offsite
#   OFFSITE_RCLONE=remote:dir    optional: rclone copy each archive offsite
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/hinyerevan}"
CRON_FILE=/etc/cron.d/hinyerevan-backup
LOG_FILE=/var/log/hinyerevan-backup.log
HOUR="${HOUR:-3}"
KEEP="${KEEP:-7}"

touch "$LOG_FILE"

# Build the environment prefix passed to the cron command.
ENV_PREFIX="KEEP=$KEEP"
[ -n "${OFFSITE_SCP:-}" ]    && ENV_PREFIX="$ENV_PREFIX OFFSITE_SCP='$OFFSITE_SCP'"
[ -n "${OFFSITE_RCLONE:-}" ] && ENV_PREFIX="$ENV_PREFIX OFFSITE_RCLONE='$OFFSITE_RCLONE'"

cat > "$CRON_FILE" <<EOF
# HinYerevan daily full backup -> /root/backups (newest $KEEP kept)
SHELL=/bin/bash
0 $HOUR * * * root $ENV_PREFIX bash $APP_DIR/deploy/backup.sh >> $LOG_FILE 2>&1
EOF
chmod 644 "$CRON_FILE"

service cron reload 2>/dev/null || systemctl reload cron 2>/dev/null || true

echo "Installed $CRON_FILE:"
cat "$CRON_FILE"
echo
echo "Backups run daily at ${HOUR}:00 server time; log: $LOG_FILE"
echo "Run an immediate backup now with: bash $APP_DIR/deploy/backup.sh"
