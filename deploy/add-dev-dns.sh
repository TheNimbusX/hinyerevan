#!/bin/bash
set -euo pipefail
ZONE=/etc/bind/domains/hinyerevan.com
if grep -q '^dev[[:space:]]' "$ZONE" || grep -q '^dev\.hinyerevan' "$ZONE"; then
  echo "dev record already exists:"
  grep dev "$ZONE" || true
  exit 0
fi
cp -a "$ZONE" "${ZONE}.bak.$(date +%Y%m%d%H%M%S)"
# bump SOA serial (YYYYMMDDNN)
sed -i 's/(2023081805 /(2026060101 /' "$ZONE"
echo 'dev	IN	A	45.138.25.76' >> "$ZONE"
named-checkzone hinyerevan.com "$ZONE"
systemctl reload named
echo "Added dev -> 45.138.25.76"
grep dev "$ZONE"
