#!/bin/bash
set -e
cd /var/www/hinyerevan
git fetch origin dev
git checkout dev
bash deploy/_remote-deploy-now.sh
