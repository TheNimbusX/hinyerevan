#!/bin/bash
set -e
cd /var/www/hinyerevan
bash deploy/deploy-dev.sh
bash deploy/_rebuild-prod-frontend.sh
