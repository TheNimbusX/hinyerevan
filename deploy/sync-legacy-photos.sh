#!/bin/bash
# Incremental sync of legacy photo files from live hinyerevan.com to dev VPS.
# Requires /root/.hinyerevan-secrets.env (chmod 600). Run on VPS only.
set -euo pipefail

SECRETS_FILE="${SECRETS_FILE:-/root/.hinyerevan-secrets.env}"
OLD_LEGACY_ROOT="${OLD_LEGACY_ROOT:-/var/www/hinyerevan/data/www/hin-yerevan}"
VPS_LEGACY_ROOT="${VPS_LEGACY_ROOT:-/var/www/hinyerevan/legacy}"

if [[ ! -f "$SECRETS_FILE" ]]; then
  echo "Missing $SECRETS_FILE" >&2
  exit 1
fi

# shellcheck disable=SC1090
source "$SECRETS_FILE"

: "${OLD_SSH_HOST:?}"
: "${OLD_SSH_USER:?}"
: "${OLD_SSH_PASS:?}"

apt-get install -y rsync sshpass >/dev/null 2>&1 || true
mkdir -p "$VPS_LEGACY_ROOT/photos"

export SSHPASS="$OLD_SSH_PASS"
RSYNC_RSH="sshpass -e ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30"

for sub in 192x192 x o users; do
  echo "==> rsync photos/$sub"
  rsync -avz --partial --append-verify \
    -e "$RSYNC_RSH" \
    "${OLD_SSH_USER}@${OLD_SSH_HOST}:${OLD_LEGACY_ROOT}/photos/${sub}/" \
    "${VPS_LEGACY_ROOT}/photos/${sub}/"
done

if [[ -f "${OLD_LEGACY_ROOT}/templates/white.png" ]]; then
  echo "==> rsync templates/white.png"
  mkdir -p "${VPS_LEGACY_ROOT}/templates"
  rsync -avz -e "$RSYNC_RSH" \
    "${OLD_SSH_USER}@${OLD_SSH_HOST}:${OLD_LEGACY_ROOT}/templates/white.png" \
    "${VPS_LEGACY_ROOT}/templates/"
fi

chown -R www-data:www-data "$VPS_LEGACY_ROOT"
rm -rf /var/www/hinyerevan/backend/storage/app/watermarked/* 2>/dev/null || true

echo "==> counts"
for sub in 192x192 x o users; do
  d="${VPS_LEGACY_ROOT}/photos/${sub}"
  if [[ -d "$d" ]]; then
    total=$(find "$d" -type f | wc -l)
    empty=$(find "$d" -type f -size 0 | wc -l)
    echo "$sub: $total files, $empty empty"
  fi
done

echo "Legacy photo sync complete."
