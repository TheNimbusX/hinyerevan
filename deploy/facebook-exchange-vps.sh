#!/bin/bash
# Exchange short-lived page token for long-lived (~60 days) and update backend/.env on VPS.
# Usage on server:
#   export FACEBOOK_APP_SECRET='<App Secret from HinYerevanPage 443529411008579>'
#   bash /var/www/hinyerevan/deploy/facebook-exchange-vps.sh
set -euo pipefail

ROOT="${1:-/var/www/hinyerevan}"
ENV_FILE="${ROOT}/backend/.env"

: "${FACEBOOK_APP_SECRET:?Set FACEBOOK_APP_SECRET (HinYerevanPage app secret from Meta → Settings → Basic)}"

if ! grep -q '^FACEBOOK_PAGE_ACCESS_TOKEN=' "$ENV_FILE"; then
  echo "Missing FACEBOOK_PAGE_ACCESS_TOKEN in $ENV_FILE" >&2
  exit 1
fi

SHORT=$(grep '^FACEBOOK_PAGE_ACCESS_TOKEN=' "$ENV_FILE" | cut -d= -f2- | tr -d '\r')
if [ -z "$SHORT" ]; then
  echo "FACEBOOK_PAGE_ACCESS_TOKEN is empty" >&2
  exit 1
fi

# Persist app secret
export FACEBOOK_APP_ID="${FACEBOOK_APP_ID:-4435294110080579}"
export FACEBOOK_PLUGIN_APP_ID="${FACEBOOK_PLUGIN_APP_ID:-802992039416856}"
export FACEBOOK_PAGE_ID="${FACEBOOK_PAGE_ID:-$(grep '^FACEBOOK_PAGE_ID=' "$ENV_FILE" | cut -d= -f2-)}"
export FACEBOOK_PAGE_URL="${FACEBOOK_PAGE_URL:-$(grep '^FACEBOOK_PAGE_URL=' "$ENV_FILE" | cut -d= -f2-)}"
export FACEBOOK_PAGE_ACCESS_TOKEN="$SHORT"
bash "${ROOT}/deploy/set-facebook-page-env.sh" "$ENV_FILE"

cd "${ROOT}/backend"
php artisan config:clear

if ! php artisan facebook:exchange-token "$SHORT" --app-secret="$FACEBOOK_APP_SECRET" --write-env; then
  echo "Token exchange failed." >&2
  exit 1
fi

php artisan config:cache
php artisan facebook:diagnose

echo "OK: long-lived FACEBOOK_PAGE_ACCESS_TOKEN saved to $ENV_FILE"
