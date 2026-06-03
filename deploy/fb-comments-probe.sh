#!/bin/bash
set -euo pipefail
cd /var/www/hinyerevan/backend
POST="${1:-122100145761351658}"
TOKEN="$(grep -m1 '^FACEBOOK_PAGE_ACCESS_TOKEN=' .env | cut -d= -f2- | tr -d '\r"')"
V=v19.0

echo "=== stream (flat) ==="
curl -sS "https://graph.facebook.com/${V}/${POST}/comments?filter=stream&fields=id,message,created_time,from{name,picture},parent&limit=100&access_token=${TOKEN}"
echo ""
echo "=== toplevel + nested replies ==="
curl -sS "https://graph.facebook.com/${V}/${POST}/comments?filter=toplevel&fields=id,message,created_time,from{name,picture},comments.limit(50){id,message,created_time,from{name,picture},parent}&limit=100&access_token=${TOKEN}"
echo ""
