# Sync local working tree to dev VPS WITHOUT git reset --hard.
# Usage: powershell -File deploy/_sync-local-to-vps.ps1
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$hostKey = 'SHA256:r8XxZ+kwSpPRRhBYu0TAVUoEYDNqyMUUAX6DhbMuggw'
$server = 'root@45.138.25.76'
$pw = $env:HINYEREVAN_DEV_PW
if (-not $pw) { throw 'Set $env:HINYEREVAN_DEV_PW before running this script.' }

function Scp-Dir($local, $remote) {
  & pscp -batch -hostkey $hostKey -pw $pw -r "$local" "${server}:$remote"
  if ($LASTEXITCODE -ne 0) { throw "pscp failed: $local" }
}

function Scp-File($local, $remote) {
  & pscp -batch -hostkey $hostKey -pw $pw "$local" "${server}:$remote"
  if ($LASTEXITCODE -ne 0) { throw "pscp failed: $local" }
}

Write-Host "=== Sync backend ==="
Scp-Dir "$root\backend\app" '/var/www/hinyerevan/backend/'
Scp-Dir "$root\backend\config" '/var/www/hinyerevan/backend/'
Scp-Dir "$root\backend\routes" '/var/www/hinyerevan/backend/'
Scp-Dir "$root\backend\lang" '/var/www/hinyerevan/backend/'
if (Test-Path "$root\backend\resources") {
  Scp-Dir "$root\backend\resources" '/var/www/hinyerevan/backend/'
}

Write-Host "=== Sync frontend ==="
Scp-Dir "$root\frontend\src" '/var/www/hinyerevan/frontend/'
Scp-File "$root\frontend\index.html" '/var/www/hinyerevan/frontend/index.html'
Scp-File "$root\frontend\vite.config.js" '/var/www/hinyerevan/frontend/vite.config.js'
if (Test-Path "$root\frontend\public") {
  Scp-Dir "$root\frontend\public" '/var/www/hinyerevan/frontend/'
}

Write-Host "=== Sync deploy scripts ==="
Scp-Dir "$root\deploy" '/var/www/hinyerevan/'

Write-Host "=== Rebuild on server ==="
$remoteCmd = @'
set -e
cd /var/www/hinyerevan
chmod +x deploy/_rebuild-frontend.sh deploy/setup-dev-auth.sh 2>/dev/null || true
grep -q '^DEV_AUTH_ENABLED=true' backend/.env || bash deploy/setup-dev-auth.sh
cd backend
php artisan route:clear
php artisan config:cache
bash /var/www/hinyerevan/deploy/_rebuild-frontend.sh
curl -s https://dev.hinyerevan.com/api/dev-auth/status || true
echo
'@
$remoteCmd = $remoteCmd -replace "`r`n", "`n"
& plink -ssh -batch -hostkey $hostKey -pw $pw $server $remoteCmd
if ($LASTEXITCODE -ne 0) { throw "remote rebuild failed" }

Write-Host "=== DONE ==="
