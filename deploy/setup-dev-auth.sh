#!/bin/bash
# Protect hinyerevan.ru dev stand with HTTP basic auth.
# Run on VPS: bash /var/www/hinyerevan/deploy/setup-dev-auth.sh
set -euo pipefail

DEV_USER="${DEV_HTTP_USER:-admin}"
DEV_PASS="${DEV_HTTP_PASS:-merp2026WEB}"

apt-get install -y apache2-utils >/dev/null 2>&1 || true

htpasswd -bc /etc/nginx/.htpasswd-hinyerevan-dev "$DEV_USER" "$DEV_PASS"
chmod 640 /etc/nginx/.htpasswd-hinyerevan-dev
chown root:www-data /etc/nginx/.htpasswd-hinyerevan-dev

cp /var/www/hinyerevan/deploy/nginx-hinyerevan.conf /etc/nginx/sites-available/hinyerevan
nginx -t
systemctl reload nginx

echo "Dev auth enabled for hinyerevan.ru (user: $DEV_USER)"
