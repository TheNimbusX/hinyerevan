#!/bin/bash
# Usage: ./setup-ssl.sh dev.hinyerevan.com
# Requires: DNS A-record for DOMAIN → this server, ports 80/443 open.
set -euo pipefail
DOMAIN="${1:?Usage: $0 dev.example.com}"
EMAIL="${2:-admin@${DOMAIN}}"

apt-get update -qq
apt-get install -y certbot python3-certbot-nginx

certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect

echo ""
echo "Update /var/www/hinyerevan/backend/.env:"
echo "  OAUTH_REDIRECT_BASE=https://${DOMAIN}"
echo "  FRONTEND_URL=https://${DOMAIN}"
echo "  APP_URL=https://${DOMAIN}"
echo ""
echo "VK redirect URL:"
echo "  https://${DOMAIN}/api/auth/social/vkontakte/callback"
echo ""
echo "Then: cd /var/www/hinyerevan/backend && php artisan config:cache"
