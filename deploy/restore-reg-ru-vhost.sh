#!/bin/bash
# Restore original ISPmanager vhost on old reg.ru (undo proxy experiment).
set -euo pipefail
SECRETS_FILE="${SECRETS_FILE:-/root/.hinyerevan-secrets.env}"
REMOTE_CONF=/etc/nginx/vhosts/hinyerevan/hinyerevan.com.conf
source "$SECRETS_FILE"
export SSHPASS="$OLD_SSH_PASS"
BACKUP=$(sshpass -e ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 "${OLD_SSH_USER}@${OLD_SSH_HOST}" "ls -1t /root/hinyerevan.com.conf.bak-* 2>/dev/null | head -1")
if [[ -z "$BACKUP" ]]; then
  echo "No backup found on reg.ru" >&2
  exit 1
fi
sshpass -e ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 "${OLD_SSH_USER}@${OLD_SSH_HOST}" "
  cp -a '$BACKUP' '$REMOTE_CONF'
  nginx -t
  systemctl reload nginx
  echo restored_from=$BACKUP
"
