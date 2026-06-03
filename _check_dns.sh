#!/bin/bash
echo "=== dev VPS ==="
hostname -f
curl -s ifconfig.me 2>/dev/null || curl -s icanhazip.com
echo ""
grep -E 'server_name|listen' /etc/nginx/sites-enabled/* 2>/dev/null | head -20
echo "=== DNS dev.hinyerevan.com ==="
dig +short dev.hinyerevan.com A 2>/dev/null || host dev.hinyerevan.com 2>/dev/null
