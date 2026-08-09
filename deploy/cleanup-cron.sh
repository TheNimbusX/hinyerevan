#!/bin/bash
# Weekly disk cleanup: watermark render cache, logs, journal, apt cache.
set -euo pipefail

WM=/var/www/hinyerevan/backend/storage/app/watermarked
LOG=/var/www/hinyerevan/backend/storage/logs/laravel.log

# Watermarked images regenerate on demand; drop ones untouched for a week.
[ -d "$WM" ] && find "$WM" -type f -atime +7 -delete 2>/dev/null || true

# Keep the log from growing without bound.
[ -f "$LOG" ] && [ "$(stat -c%s "$LOG")" -gt 52428800 ] && : > "$LOG" || true

journalctl --vacuum-size=200M >/dev/null 2>&1 || true
apt-get clean >/dev/null 2>&1 || true

echo "$(date '+%F %T') cleanup done, free: $(df -h / | awk 'NR==2{print $4}')"
