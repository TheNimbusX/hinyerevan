#!/bin/bash
# ============================================================================
# Install automated backups for HinYerevan.
#
# Because the photo library is large (~13 GB) and the disk is small, the
# DAILY automated backup defaults to MODE=db (database + config only — a few MB,
# this is what actually changes every day). A full archive (DB + all photos) is
# scheduled WEEKLY only if there's room or an offsite target is configured.
#
# Run once on the VPS (idempotent):
#   bash /var/www/hinyerevan/deploy/setup-backup-cron.sh
#
# Env overrides:
#   HOUR=3                       hour of day (server time) to run, default 03:00
#   KEEP=14                      db archives to keep
#   FULL_KEEP=1                  full archives to keep locally
#   FULL_DOW=                    weekday (0-6, Sun=0) for the weekly full backup.
#                                Empty (default) = no scheduled full backup
#                                unless an offsite target is set.
#   OFFSITE_SCP=user@host:/dir   optional: scp each archive offsite
#   OFFSITE_RCLONE=remote:dir    optional: rclone copy each archive offsite
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/hinyerevan}"
CRON_FILE=/etc/cron.d/hinyerevan-backup
LOG_FILE=/var/log/hinyerevan-backup.log
HOUR="${HOUR:-3}"
KEEP="${KEEP:-14}"
FULL_KEEP="${FULL_KEEP:-1}"
FULL_DOW="${FULL_DOW:-}"

touch "$LOG_FILE"

OFF=""
[ -n "${OFFSITE_SCP:-}" ]    && OFF="$OFF OFFSITE_SCP='$OFFSITE_SCP'"
[ -n "${OFFSITE_RCLONE:-}" ] && OFF="$OFF OFFSITE_RCLONE='$OFFSITE_RCLONE'"

# Schedule a weekly full backup automatically when an offsite target exists
# (so the big file does not pile up on the local disk).
if [ -z "$FULL_DOW" ] && [ -n "$OFF" ]; then FULL_DOW=0; fi

{
  echo "# HinYerevan automated backups (log: $LOG_FILE)"
  echo "SHELL=/bin/bash"
  echo "# Daily database-only backup (tiny, safe on small disks)"
  echo "0 $HOUR * * * root MODE=db KEEP=$KEEP$OFF bash $APP_DIR/deploy/backup.sh >> $LOG_FILE 2>&1"
  if [ -n "$FULL_DOW" ]; then
    echo "# Weekly full backup (DB + all photos)"
    echo "30 $HOUR * * $FULL_DOW root MODE=full KEEP=$FULL_KEEP$OFF bash $APP_DIR/deploy/backup.sh >> $LOG_FILE 2>&1"
  fi
} > "$CRON_FILE"
chmod 644 "$CRON_FILE"

service cron reload 2>/dev/null || systemctl reload cron 2>/dev/null || true

echo "Installed $CRON_FILE:"
cat "$CRON_FILE"
echo
echo "Daily DB backups run at ${HOUR}:00 server time; log: $LOG_FILE"
[ -z "$FULL_DOW" ] && echo "No scheduled FULL (photo) backup — run one before migrating, or set OFFSITE_* / FULL_DOW."
echo "Manual full backup (one portable file): bash $APP_DIR/deploy/backup.sh"
