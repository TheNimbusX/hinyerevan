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

# --- 4) decide whether to write a local one-file archive --------------------
# db-only archives get their own name prefix so rotation never mixes them with
# (large) full archives.
PREFIX="hinyerevan"; [ "$MODE" = "db" ] && PREFIX="hinyerevan-db"
OUT="$BACKUP_DIR/$PREFIX-$STAMP.tar"
TAR_MODE=(cf)
if [ "$COMPRESS" = "1" ]; then OUT="$OUT.gz"; TAR_MODE=(czf); fi

TAR_ARGS=(--exclude='storage/app/watermarked' --exclude='storage/app/cache' \
          -C "$WORK" meta db config)
WRITE_LOCAL=1
if [ "$MODE" = "full" ]; then
  if [ -d "$LEGACY_ROOT" ]; then
    PHOTOS_KB="$(du -sk "$LEGACY_ROOT" 2>/dev/null | cut -f1)"
    echo "==> Photos: $LEGACY_ROOT ($(du -sh "$LEGACY_ROOT" 2>/dev/null | cut -f1))"
    TAR_ARGS+=(-C "$(dirname "$LEGACY_ROOT")" "$LEGACY_BASE")
    # A full one-file archive ~= the photo tree. Only write it locally when
    # there is clearly enough free disk (otherwise we'd fill the disk and take
    # the site down). When it doesn't fit we fall back to an offsite file-sync
    # (rclone) instead, which streams file-by-file and needs no local space.
    FREE_KB="$(df -Pk "$BACKUP_DIR" | awk 'NR==2{print $4}')"
    NEED_KB=$(( ${PHOTOS_KB:-0} + 524288 ))   # photos + ~512MB headroom
    if [ "$FORCE" != "1" ] && [ "${FREE_KB:-0}" -lt "$NEED_KB" ]; then
      WRITE_LOCAL=0
      echo "==> Not enough local disk for a one-file archive"
      echo "    (need ~$((NEED_KB/1024))MB, free $((FREE_KB/1024))MB)."
      if [ -z "${OFFSITE_RCLONE:-}" ]; then
        echo "FATAL: no OFFSITE_RCLONE set, so there is nowhere to put the full" >&2
        echo "       backup. Free disk and retry, set OFFSITE_RCLONE, or FORCE=1." >&2
        exit 1
      fi
      echo "    -> will mirror photos offsite with rclone instead."
    fi
  else
    echo "WARN: legacy root $LEGACY_ROOT not found — photos NOT included" >&2
  fi
  [ -d "$BACKEND_DIR/storage/app" ] && TAR_ARGS+=(-C "$BACKEND_DIR" storage/app)
else
  echo "==> MODE=db: database + config only (no photos)"
fi

if [ "$WRITE_LOCAL" = "1" ]; then
  echo "==> Writing $OUT"
  tar "${TAR_MODE[@]}" "$OUT" "${TAR_ARGS[@]}"
  echo "==> DONE (local): $OUT ($(du -h "$OUT" | cut -f1))"
fi

# --- 5) offsite ------------------------------------------------------------
# scp only makes sense for an existing local one-file archive.
if [ -n "${OFFSITE_SCP:-}" ] && [ "$WRITE_LOCAL" = "1" ]; then
  echo "==> scp to $OFFSITE_SCP"
  scp -o StrictHostKeyChecking=accept-new "$OUT" "$OFFSITE_SCP/" || echo "WARN: offsite scp failed" >&2
fi

if [ -n "${OFFSITE_RCLONE:-}" ]; then
  echo "==> Offsite (rclone) -> $OFFSITE_RCLONE"
  # Small, always: stamped DB dump + latest config/meta (low bandwidth).
  rclone copyto "$WORK/db/$DB_NAME.sql.gz" "$OFFSITE_RCLONE/db/$DB_NAME-$STAMP.sql.gz" \
    || echo "WARN: rclone db upload failed" >&2
  rclone copy "$WORK/config" "$OFFSITE_RCLONE/config" || echo "WARN: rclone config upload failed" >&2
  rclone copy "$WORK/meta"   "$OFFSITE_RCLONE/meta"   || echo "WARN: rclone meta upload failed" >&2

  if [ "$MODE" = "full" ]; then
    # Mirror photos file-by-file: incremental, resumable, low memory — the only
    # safe way to push 10+ GB from a small-RAM box.
    if [ -d "$LEGACY_ROOT" ]; then
      echo "==> rclone sync photos -> $OFFSITE_RCLONE/legacy"
      rclone sync "$LEGACY_ROOT" "$OFFSITE_RCLONE/legacy" \
        --transfers 4 --checkers 8 --fast-list || echo "WARN: rclone photo sync failed" >&2
    fi
    if [ -d "$BACKEND_DIR/storage/app" ]; then
      echo "==> rclone sync storage/app -> $OFFSITE_RCLONE/storage-app"
      rclone sync "$BACKEND_DIR/storage/app" "$OFFSITE_RCLONE/storage-app" \
        --exclude 'watermarked/**' --exclude 'cache/**' \
        --transfers 4 --checkers 8 --fast-list || echo "WARN: rclone storage sync failed" >&2
    fi
  fi

  # Prune remote DB history to newest KEEP.
  echo "==> Pruning remote DB dumps (keep newest $KEEP)"
  rclone lsf "$OFFSITE_RCLONE/db" --files-only 2>/dev/null \
    | grep -E "^${DB_NAME}-[0-9].*\.sql\.gz$" | sort | head -n "-$KEEP" \
    | while read -r f; do rclone deletefile "$OFFSITE_RCLONE/db/$f" 2>/dev/null || true; done
fi

# --- 6) prune local one-file archives (same prefix only) -------------------
echo "==> Keeping newest $KEEP local $MODE archive(s) in $BACKUP_DIR"
ls -1t "$BACKUP_DIR/$PREFIX"-*.tar "$BACKUP_DIR/$PREFIX"-*.tar.gz 2>/dev/null \
  | grep -E "/$PREFIX-[0-9]" \
  | tail -n +"$((KEEP + 1))" | xargs -r rm -f
ls -lh "$BACKUP_DIR"/ 2>/dev/null || true
