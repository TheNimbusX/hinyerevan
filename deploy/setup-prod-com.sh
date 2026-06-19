#!/bin/bash
# Enable hinyerevan.com on VPS: nginx + Let's Encrypt + .env prod URLs.
set -euo pipefail

APP_DIR=/var/www/hinyerevan/backend
DEPLOY_DIR=/var/www/hinyerevan/deploy
EMAIL="${CERTBOT_EMAIL:-admin@hinyerevan.com}"

apt-get install -y certbot python3-certbot-nginx >/dev/null 2>&1 || true

# HTTP vhost (serves site until SSL is issued).
cat > /etc/nginx/sites-available/hinyerevan-com <<'NGINX'
server {
    listen 80;
    server_name hinyerevan.com www.hinyerevan.com;

    client_max_body_size 64M;
    root /var/www/hinyerevan/frontend/dist;
    index index.html;

    location /.well-known/acme-challenge/ {
        root /var/www/hinyerevan/frontend/dist;
    }
    location /api {
        root /var/www/hinyerevan/backend/public;
        try_files $uri /index.php?$query_string;
    }
    location /sanctum {
        root /var/www/hinyerevan/backend/public;
        try_files $uri /index.php?$query_string;
    }
    location ~ \.php$ {
        root /var/www/hinyerevan/backend/public;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param REQUEST_URI $request_uri;
        include fastcgi_params;
        fastcgi_read_timeout 180s;
        fastcgi_send_timeout 180s;
    }
    location / {
        try_files $uri $uri/ /index.html;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/hinyerevan-com /etc/nginx/sites-enabled/hinyerevan-com
nginx -t
systemctl reload nginx

echo "==> DNS check (only 45.138.25.76 should appear; remove AAAA + old A 31.31.199.153 in reg.ru)"
getent ahosts hinyerevan.com | awk '{print $1}' | sort -u
getent ahosts www.hinyerevan.com | awk '{print $1}' | sort -u

if getent ahosts hinyerevan.com | grep -q '^2a00:'; then
  echo "WARN: AAAA (IPv6) still points elsewhere — delete AAAA records in reg.ru, then re-run this script."
fi

if certbot certonly --webroot \
  -w /var/www/hinyerevan/frontend/dist \
  -d hinyerevan.com \
  -d www.hinyerevan.com \
  --email "$EMAIL" \
  --agree-tos \
  --non-interactive \
  --keep-until-expiring; then
  cp "$DEPLOY_DIR/nginx-hinyerevan-prod.conf" /etc/nginx/sites-available/hinyerevan-com
  nginx -t
  systemctl reload nginx
  echo "SSL installed."
else
  echo "SSL pending — site works on http://hinyerevan.com until AAAA records are removed and script re-run."
fi

ENV_FILE="$APP_DIR/.env"
for kv in \
  'APP_URL=https://hinyerevan.com' \
  'FRONTEND_URL=https://hinyerevan.com' \
  'OAUTH_REDIRECT_BASE=https://hinyerevan.com' \
  'SANCTUM_STATEFUL_DOMAINS=hinyerevan.com,www.hinyerevan.com,hinyerevan.ru,www.hinyerevan.ru' \
  'SESSION_DOMAIN=.hinyerevan.com'; do
  key="${kv%%=*}"
  if grep -q "^${key}=" "$ENV_FILE"; then
    sed -i "s|^${key}=.*|${kv}|" "$ENV_FILE"
  else
    echo "$kv" >> "$ENV_FILE"
  fi
done

cd "$APP_DIR"
php artisan config:cache
php artisan route:cache

echo "==> smoke"
curl -s -o /dev/null -w 'http_com:%{http_code}\n' http://hinyerevan.com/
curl -s -o /dev/null -w 'api_com:%{http_code}\n' http://hinyerevan.com/api/photos/markers?limit=1
if [[ -f /etc/letsencrypt/live/hinyerevan.com/fullchain.pem ]]; then
  curl -sk -o /dev/null -w 'https_com:%{http_code}\n' https://hinyerevan.com/
fi
