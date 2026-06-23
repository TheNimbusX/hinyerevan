#!/bin/bash
# ============================================================================
# HinYerevan — restore from a backup made by deploy/backup.sh onto THIS VPS.
#
# Usage (run as root on the NEW VPS):
#   # A) from a single portable file made by backup.sh:
#   bash restore.sh /path/to/hinyerevan-YYYYMMDD-HHMMSS.tar [--provision]
#
#   # B) from the offsite Yandex.Disk mirror (rclone remote must be configured):
#   bash restore.sh --from-rclone yadisk:hinyerevan [--provision]
#
#   --provision   first install the full stack (nginx, php8.1-fpm, MySQL,
#                 Node 20, Composer, git, rclone) on a clean Debian/Ubuntu box.
#                 Omit it if the server already has the stack.
#
# What it does:
#   1. (optional) installs packages
#   2. clones the app from git and pins it to the backup's commit
#   3. restores backend/.env, secrets, photos and storage/app
#   4. creates the MySQL database + user and imports the dump
#   5. restores the nginx vhost + scheduler cron
#   6. runs the normal deploy (composer, migrate, frontend build, permissions)
#
# Env overrides: APP_DIR, REPO_URL, BRANCH, SECRETS_FILE
# ============================================================================
set -euo pipefail

ARCHIVE=""
FROM_RCLONE=""
PROVISION=0
while [ $# -gt 0 ]; do
  case "$1" in
    --provision)   PROVISION=1 ;;
    --from-rclone) FROM_RCLONE="${2:-}"; shift ;;
    *)             [ -z "$ARCHIVE" ] && ARCHIVE="$1" ;;
  esac
  shift
done

if [ -z "$FROM_RCLONE" ]; then
  if [ -z "$ARCHIVE" ] || [ ! -f "$ARCHIVE" ]; then
    echo "Usage:" >&2
    echo "  bash restore.sh <archive.tar[.gz]> [--provision]" >&2
    echo "  bash restore.sh --from-rclone <remote:path> [--provision]" >&2
    exit 1
  fi
  ARCHIVE="$(readlink -f "$ARCHIVE")"
fi

APP_DIR="${APP_DIR:-/var/www/hinyerevan}"
REPO_URL="${REPO_URL:-https://github.com/TheNimbusX/hinyerevan.git}"
BRANCH="${BRANCH:-dev}"
SECRETS_FILE="${SECRETS_FILE:-/root/.hinyerevan-secrets.env}"
BACKEND_DIR="$APP_DIR/backend"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# --- 1) provision (optional) ------------------------------------------------
if [ "$PROVISION" = "1" ]; then
  echo "==> Provisioning packages (Debian/Ubuntu)"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y nginx git unzip curl gzip rsync mysql-server \
    php8.1-fpm php8.1-cli php8.1-mysql php8.1-mbstring php8.1-xml \
    php8.1-curl php8.1-gd php8.1-zip php8.1-bcmath php8.1-intl
  if ! command -v composer >/dev/null 2>&1; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
  fi
  if ! command -v node >/dev/null 2>&1; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
  fi
  if ! command -v rclone >/dev/null 2>&1; then
    curl -fsSL https://rclone.org/install.sh | bash || true
  fi
  systemctl enable --now mysql php8.1-fpm nginx || true
fi

# --- 2) get metadata (manifest/config/db) ----------------------------------
if [ -n "$FROM_RCLONE" ]; then
  command -v rclone >/dev/null 2>&1 || { echo "FATAL: rclone not installed (use --provision)"; exit 1; }
  if ! rclone lsf "$FROM_RCLONE/meta" >/dev/null 2>&1; then
    echo "FATAL: cannot read $FROM_RCLONE — configure the rclone remote first." >&2
    echo "  e.g. for Yandex.Disk WebDAV:" >&2
    echo "  rclone config create yadisk webdav url https://webdav.yandex.ru vendor other \\" >&2
    echo "    user <login> pass <app-password> --obscure" >&2
    exit 1
  fi
  echo "==> Fetching metadata from $FROM_RCLONE"
  rclone copy "$FROM_RCLONE/meta"   "$WORK/meta"
  rclone copy "$FROM_RCLONE/config" "$WORK/config"
  rclone copy "$FROM_RCLONE/db"     "$WORK/db"
else
  echo "==> Extracting $ARCHIVE"
  tar xf "$ARCHIVE" -C "$WORK"
fi
MANIFEST="$WORK/meta/manifest.txt"
[ -f "$MANIFEST" ] || { echo "FATAL: archive has no meta/manifest.txt" >&2; exit 1; }
echo "---- manifest ----"; cat "$MANIFEST"; echo "------------------"
mget() { grep -m1 "^$1:" "$MANIFEST" | sed "s/^$1:[[:space:]]*//"; }
LEGACY_ROOT="$(mget legacy_root)";        LEGACY_ROOT="${LEGACY_ROOT:-$APP_DIR/legacy}"
LEGACY_BASE="$(mget legacy_archive_dir)"
GIT_COMMIT="$(mget git_commit)"

# --- 3) code from git, pinned to the backup commit -------------------------
echo "==> Code -> $APP_DIR"
if [ ! -d "$APP_DIR/.git" ]; then
  mkdir -p "$(dirname "$APP_DIR")"
  git clone "$REPO_URL" "$APP_DIR"
fi
git -C "$APP_DIR" fetch --all --prune || true
if [ -n "$GIT_COMMIT" ] && [ "$GIT_COMMIT" != "unknown" ] && git -C "$APP_DIR" cat-file -e "${GIT_COMMIT}^{commit}" 2>/dev/null; then
  git -C "$APP_DIR" checkout -f "$GIT_COMMIT"
else
  echo "WARN: commit $GIT_COMMIT unavailable; using origin/$BRANCH"
  git -C "$APP_DIR" checkout -f "$BRANCH" 2>/dev/null || git -C "$APP_DIR" checkout -f -b "$BRANCH" "origin/$BRANCH"
  git -C "$APP_DIR" reset --hard "origin/$BRANCH"
fi

# --- 4) config + secrets ----------------------------------------------------
echo "==> Restoring .env / secrets"
install -D -m 600 "$WORK/config/backend.env" "$BACKEND_DIR/.env"
[ -f "$WORK/config/secrets.env" ] && install -m 600 "$WORK/config/secrets.env" "$SECRETS_FILE"

# --- 5) photos + storage/app -----------------------------------------------
echo "==> Restoring photos -> $LEGACY_ROOT"
mkdir -p "$LEGACY_ROOT"
if [ -n "$FROM_RCLONE" ]; then
  rclone sync "$FROM_RCLONE/legacy" "$LEGACY_ROOT" --transfers 4 --checkers 8 --fast-list
  if rclone lsf "$FROM_RCLONE/storage-app" >/dev/null 2>&1; then
    mkdir -p "$BACKEND_DIR/storage/app"
    rclone sync "$FROM_RCLONE/storage-app" "$BACKEND_DIR/storage/app" --transfers 4 --checkers 8 --fast-list
  fi
elif [ -n "$LEGACY_BASE" ] && [ -d "$WORK/$LEGACY_BASE" ]; then
  cp -a "$WORK/$LEGACY_BASE/." "$LEGACY_ROOT/"
  if [ -d "$WORK/storage/app" ]; then
    mkdir -p "$BACKEND_DIR/storage/app"
    cp -a "$WORK/storage/app/." "$BACKEND_DIR/storage/app/"
  fi
else
  echo "WARN: no photo tree in archive ($LEGACY_BASE)"
fi

# --- 6) database ------------------------------------------------------------
ENV_FILE="$BACKEND_DIR/.env"
env_get() { grep -m1 "^$1=" "$ENV_FILE" | cut -d= -f2- | tr -d '\r"' | sed "s/^'//; s/'\$//"; }
DB_HOST="$(env_get DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_get DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(env_get DB_DATABASE)"
DB_USER="$(env_get DB_USERNAME)"
DB_PASS="$(env_get DB_PASSWORD)"

echo "==> Creating database '$DB_NAME' and user '$DB_USER'"
if mysql -e "SELECT 1" >/dev/null 2>&1; then
  mysql <<SQL || echo "WARN: DB/user auto-create failed — create them manually, then re-run import below"
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
else
  echo "WARN: no local root MySQL access; assuming database + user already exist"
fi

echo "==> Importing database dump"
# newest dump wins (the offsite mirror keeps several stamped dumps).
DUMP_FILE="$(ls -1 "$WORK"/db/*.sql.gz 2>/dev/null | sort | tail -n1)"
[ -n "$DUMP_FILE" ] || { echo "FATAL: no db dump in archive" >&2; exit 1; }
echo "    using $(basename "$DUMP_FILE")"
export MYSQL_PWD="$DB_PASS"
gunzip -c "$DUMP_FILE" | mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME"

# --- 7) nginx vhost + scheduler cron ---------------------------------------
echo "==> Restoring nginx vhost + cron"
for nf in "$WORK"/config/*.nginx; do
  [ -f "$nf" ] || continue
  dest="/etc/nginx/sites-available/$(basename "$nf" .nginx)"
  cp "$nf" "$dest"
  ln -sf "$dest" "/etc/nginx/sites-enabled/$(basename "$dest")"
done
if [ -f "$WORK/config/cron-scheduler" ]; then
  cp "$WORK/config/cron-scheduler" /etc/cron.d/hinyerevan-scheduler
  chmod 644 /etc/cron.d/hinyerevan-scheduler
else
  bash "$APP_DIR/deploy/setup-cron.sh" || true
fi

# --- 8) build the app (reuses the tested deploy path) ----------------------
echo "==> Building app (composer / migrate / frontend)"
bash "$APP_DIR/deploy/deploy-dev.sh"

echo "==> Fixing permissions"
chown -R www-data:www-data "$LEGACY_ROOT" "$BACKEND_DIR/storage" "$BACKEND_DIR/bootstrap/cache" || true

echo "==> Reloading nginx"
if nginx -t 2>/dev/null; then
  systemctl reload nginx || service nginx reload || true
else
  echo "WARN: 'nginx -t' failed — most likely the Let's Encrypt cert does not exist"
  echo "      on this new server yet. Issue SSL and finalise the vhost with:"
  echo "          bash $APP_DIR/deploy/setup-prod-com.sh"
fi

echo ""
echo "==================================================================="
echo " RESTORE COMPLETE"
echo "   app:    $APP_DIR  (commit ${GIT_COMMIT:-?})"
echo "   db:     $DB_NAME"
echo "   photos: $LEGACY_ROOT"
echo " If SSL is not set up on this VPS yet, run:"
echo "   bash $APP_DIR/deploy/setup-prod-com.sh"
echo "==================================================================="
