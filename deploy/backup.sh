#!/bin/bash
# ============================================================================
# HinYerevan — full portable backup into ONE file.
#
# Produces a single archive that contains EVERYTHING needed to bring the site
# up on a brand-new VPS:
#   - MySQL database dump          (db/<name>.sql.gz)
#   - all photos / uploads         (the HINYEREVAN_LEGACY_ROOT tree)
#   - backend/.env + secrets       (config/)
#   - nginx vhost + scheduler cron (config/)
#   - manifest with git commit     (meta/)
#
# The application code itself is NOT bundled — it is restored from git by
# deploy/restore.sh, pinned to the git commit recorded in the manifest.
#
# Run ON the production VPS:
#   bash /var/www/hinyerevan/deploy/backup.sh
#
# Result: /root/backups/hinyerevan-YYYYMMDD-HHMMSS.tar
# Copy that file to the new VPS and run deploy/restore.sh there.
#
# Env overrides:
#   MODE=full                  full = DB + photos + config (default, for DR /
#                              moving to a new VPS). db = DB + config only
#                              (tiny; safe for frequent local backups).
#   BACKUP_DIR=/root/backups   where archives are written
#   KEEP=7                     how many archives to keep (older ones pruned)
#   COMPRESS=0                 1 = gzip the whole archive (slow; photos are
#                              already compressed, so off by default)
#   FORCE=0                    1 = skip the free-disk-space safety check
#   OFFSITE_SCP=user@host:/dir scp the finished archive there (optional)
#   OFFSITE_RCLONE=remote:dir  rclone copy the finished archive there (optional)
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/hinyerevan}"
BACKEND_DIR="$APP_DIR/backend"
ENV_FILE="$BACKEND_DIR/.env"
SECRETS_FILE="${SECRETS_FILE:-/root/.hinyerevan-secrets.env}"
BACKUP_DIR="${BACKUP_DIR:-/root/backups}"
KEEP="${KEEP:-7}"
COMPRESS="${COMPRESS:-0}"
MODE="${MODE:-full}"
FORCE="${FORCE:-0}"

[ -f "$ENV_FILE" ] || { echo "FATAL: missing $ENV_FILE" >&2; exit 1; }

STAMP="$(date -u +%Y%m%d-%H%M%S)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# --- read values from backend/.env -----------------------------------------
env_get() { grep -m1 "^$1=" "$ENV_FILE" | cut -d= -f2- | tr -d '\r"' | sed "s/^'//; s/'\$//"; }
DB_HOST="$(env_get DB_HOST)";     DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_get DB_PORT)";     DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(env_get DB_DATABASE)"
DB_USER="$(env_get DB_USERNAME)"
DB_PASS="$(env_get DB_PASSWORD)"
LEGACY_ROOT="$(env_get HINYEREVAN_LEGACY_ROOT)"
LEGACY_ROOT="${LEGACY_ROOT:-$APP_DIR/legacy}"

[ -n "$DB_NAME" ] || { echo "FATAL: DB_DATABASE not set in .env" >&2; exit 1; }

# --- pick MySQL auth: prefer root via local socket, fall back to .env creds -
if mysql -e "SELECT 1" >/dev/null 2>&1; then
  DUMP_AUTH=(); MYSQL_PWD_EXPORT=0
  echo "==> Using local root (socket) for MySQL"
else
  DUMP_AUTH=(-h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER"); MYSQL_PWD_EXPORT=1
  echo "==> Using .env credentials for MySQL ($DB_USER@$DB_HOST)"
fi

mkdir -p "$BACKUP_DIR" "$WORK/meta" "$WORK/db" "$WORK/config"

# --- 1) database ------------------------------------------------------------
echo "==> Dumping database '$DB_NAME'"
DUMP_OPTS=(--single-transaction --quick --routines --triggers --no-tablespaces)
# --column-statistics=0 only exists on the MySQL 8 client; MariaDB rejects it.
if mysqldump --help 2>/dev/null | grep -q -- '--column-statistics'; then
  DUMP_OPTS+=(--column-statistics=0)
fi
[ "$MYSQL_PWD_EXPORT" = "1" ] && export MYSQL_PWD="$DB_PASS"
mysqldump "${DUMP_OPTS[@]}" "${DUMP_AUTH[@]}" "$DB_NAME" | gzip -c > "$WORK/db/$DB_NAME.sql.gz"
echo "    db dump: $(du -h "$WORK/db/$DB_NAME.sql.gz" | cut -f1)"

# --- 2) config / secrets ----------------------------------------------------
echo "==> Saving config (.env, secrets, nginx, cron)"
cp "$ENV_FILE" "$WORK/config/backend.env"
[ -f "$SECRETS_FILE" ] && cp "$SECRETS_FILE" "$WORK/config/secrets.env"
for f in /etc/nginx/sites-available/hinyerevan-com /etc/nginx/sites-available/hinyerevan; do
  [ -f "$f" ] && cp "$f" "$WORK/config/$(basename "$f").nginx"
done
[ -f /etc/cron.d/hinyerevan-scheduler ] && cp /etc/cron.d/hinyerevan-scheduler "$WORK/config/cron-scheduler"

# --- 3) manifest ------------------------------------------------------------
GIT_COMMIT="$(git -C "$APP_DIR" rev-parse HEAD 2>/dev/null || echo unknown)"
GIT_BRANCH="$(git -C "$APP_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
GIT_REMOTE="$(git -C "$APP_DIR" config --get remote.origin.url 2>/dev/null || echo unknown)"
LEGACY_BASE="$(basename "$LEGACY_ROOT")"
cat > "$WORK/meta/manifest.txt" <<EOF
schema: hinyerevan-backup/1
created_utc: $STAMP
hostname: $(hostname)
app_dir: $APP_DIR
legacy_root: $LEGACY_ROOT
legacy_archive_dir: $LEGACY_BASE
db_name: $DB_NAME
git_remote: $GIT_REMOTE
git_branch: $GIT_BRANCH
git_commit: $GIT_COMMIT
EOF
echo "$GIT_COMMIT" > "$WORK/meta/git-commit.txt"
cat "$WORK/meta/manifest.txt"

# --- 4) assemble the single archive ----------------------------------------
# db-only archives get their own name prefix so rotation never mixes them with
# (large) full archives.
PREFIX="hinyerevan"; [ "$MODE" = "db" ] && PREFIX="hinyerevan-db"
OUT="$BACKUP_DIR/$PREFIX-$STAMP.tar"
TAR_MODE=(cf)
if [ "$COMPRESS" = "1" ]; then OUT="$OUT.gz"; TAR_MODE=(czf); fi

TAR_ARGS=(--exclude='storage/app/watermarked' --exclude='storage/app/cache' \
          -C "$WORK" meta db config)
if [ "$MODE" = "full" ]; then
  if [ -d "$LEGACY_ROOT" ]; then
    PHOTOS_KB="$(du -sk "$LEGACY_ROOT" 2>/dev/null | cut -f1)"
    echo "==> Photos: $LEGACY_ROOT ($(du -sh "$LEGACY_ROOT" 2>/dev/null | cut -f1))"
    # Safety: a full archive ~= the photo tree. Refuse to write it if there
    # isn't clearly enough free space, otherwise the cron could fill the disk
    # and take the site down. Use MODE=db for routine backups, or FORCE=1.
    FREE_KB="$(df -Pk "$BACKUP_DIR" | awk 'NR==2{print $4}')"
    NEED_KB=$(( ${PHOTOS_KB:-0} + 524288 ))   # photos + ~512MB headroom
    if [ "$FORCE" != "1" ] && [ "${FREE_KB:-0}" -lt "$NEED_KB" ]; then
      echo "FATAL: not enough free space for a full backup." >&2
      echo "  need ~$((NEED_KB/1024))MB, free $((FREE_KB/1024))MB in $BACKUP_DIR." >&2
      echo "  Use MODE=db for routine backups, push offsite (OFFSITE_SCP/RCLONE)," >&2
      echo "  free space, or re-run with FORCE=1 if you know it fits." >&2
      exit 1
    fi
    TAR_ARGS+=(-C "$(dirname "$LEGACY_ROOT")" "$LEGACY_BASE")
  else
    echo "WARN: legacy root $LEGACY_ROOT not found — photos NOT included" >&2
  fi
  if [ -d "$BACKEND_DIR/storage/app" ]; then
    TAR_ARGS+=(-C "$BACKEND_DIR" storage/app)
  fi
else
  echo "==> MODE=db: database + config only (no photos)"
fi

echo "==> Writing $OUT"
tar "${TAR_MODE[@]}" "$OUT" "${TAR_ARGS[@]}"
echo "==> DONE: $OUT ($(du -h "$OUT" | cut -f1))"

# --- 5) optional offsite copy ----------------------------------------------
if [ -n "${OFFSITE_SCP:-}" ]; then
  echo "==> scp to $OFFSITE_SCP"
  scp -o StrictHostKeyChecking=accept-new "$OUT" "$OFFSITE_SCP/" || echo "WARN: offsite scp failed" >&2
fi
if [ -n "${OFFSITE_RCLONE:-}" ]; then
  echo "==> rclone copy to $OFFSITE_RCLONE"
  rclone copy "$OUT" "$OFFSITE_RCLONE" || echo "WARN: rclone copy failed" >&2
fi

# --- 6) prune old archives (only within the same prefix) -------------------
echo "==> Keeping newest $KEEP $MODE archive(s) in $BACKUP_DIR"
ls -1t "$BACKUP_DIR/$PREFIX"-*.tar "$BACKUP_DIR/$PREFIX"-*.tar.gz 2>/dev/null \
  | grep -E "/$PREFIX-[0-9]" \
  | tail -n +"$((KEEP + 1))" | xargs -r rm -f
ls -lh "$BACKUP_DIR"/ 2>/dev/null || true
