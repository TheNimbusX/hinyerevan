#!/bin/bash
# Prepare the origin (NL VPS) to sit behind Cloudflare.
#
#   bash deploy/setup-cloudflare.sh            # install real visitor IP restore (safe, run anytime)
#   bash deploy/setup-cloudflare.sh lock        # ALSO restrict 443/80 to Cloudflare IPs (run ONLY after CF works)
#
# "realip" is safe to run before enabling the orange cloud: it only takes effect
# for connections that actually originate from Cloudflare ranges.
set -euo pipefail

MODE="${1:-realip}"
CONF=/etc/nginx/conf.d/cloudflare-real-ip.conf

echo "Fetching current Cloudflare IP ranges…"
V4=$(curl -fsS https://www.cloudflare.com/ips-v4)
V6=$(curl -fsS https://www.cloudflare.com/ips-v6)

{
  echo "# Auto-generated $(date -u) by deploy/setup-cloudflare.sh"
  echo "# Restores the real visitor IP (CF-Connecting-IP) when traffic comes via Cloudflare."
  for ip in $V4; do echo "set_real_ip_from $ip;"; done
  for ip in $V6; do echo "set_real_ip_from $ip;"; done
  echo "real_ip_header CF-Connecting-IP;"
} > "$CONF"

nginx -t
systemctl reload nginx
echo "Installed $CONF — real client IPs will be honored behind Cloudflare."

if [ "$MODE" = "lock" ]; then
  echo "Locking 80/443 to Cloudflare ranges (SSH 22 stays open)…"
  ufw allow 22/tcp
  for ip in $V4 $V6; do
    ufw allow from "$ip" to any port 443 proto tcp
    ufw allow from "$ip" to any port 80 proto tcp
  done
  # Without this, ufw enable would keep default-allow; we want to drop direct 80/443.
  ufw --force enable
  ufw deny 80/tcp
  ufw deny 443/tcp
  ufw reload
  echo "Origin now only accepts 80/443 from Cloudflare. Verify the site still loads via the domain!"
  echo "If something breaks: 'ufw disable' restores direct access."
fi
