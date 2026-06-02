#!/bin/bash
echo "=== dev VPS public IP ==="
curl -s --max-time 5 ifconfig.me || curl -s icanhazip.com
echo ""
echo "=== nginx ==="
grep -E 'server_name|listen' /etc/nginx/sites-enabled/* 2>/dev/null | head -20
echo "=== DNS dev.hinyerevan.com ==="
getent hosts dev.hinyerevan.com 2>/dev/null || true
dig +short dev.hinyerevan.com A 2>/dev/null || host dev.hinyerevan.com 2>/dev/null || nslookup dev.hinyerevan.com 2>/dev/null | tail -3
AAAA=$(dig +short dev.hinyerevan.com AAAA @8.8.8.8 2>/dev/null | head -1)
if [ -n "$AAAA" ]; then
  echo "WARN: dev.hinyerevan.com has AAAA $AAAA — remove in reg.ru or dev may fail from Russia without VPN"
fi
