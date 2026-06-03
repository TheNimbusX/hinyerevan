#!/bin/bash
ENV=/var/www/hinyerevan/backend/.env
BASE=https://hinyerevan.ru
for k in OAUTH_REDIRECT_BASE FRONTEND_URL APP_URL; do
  if grep -q "^${k}=" "$ENV"; then
    sed -i "s|^${k}=.*|${k}=${BASE}|" "$ENV"
  else
    echo "${k}=${BASE}" >> "$ENV"
  fi
done
cd /var/www/hinyerevan/backend && php artisan config:cache
grep -E '^(OAUTH_REDIRECT|FRONTEND|APP_URL)=' "$ENV"
curl -sI https://hinyerevan.ru/ | head -4
