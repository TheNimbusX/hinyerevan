#!/bin/bash
hostname
ls /usr/local/mgr5 2>/dev/null && echo ISPmanager
ls /etc/bind 2>/dev/null
grep -r hinyerevan /etc/bind 2>/dev/null | head -3
grep -r hinyerevan /var/named 2>/dev/null | head -3
command -v pdns_control 2>/dev/null
ls /etc/nginx/sites-enabled/ 2>/dev/null | head -5
