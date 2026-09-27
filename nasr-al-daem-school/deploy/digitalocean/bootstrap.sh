#!/usr/bin/env bash
# Run as root on a NEW Ubuntu 24.04 Droplet. Installs infrastructure only.
set -Eeuo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nginx postgresql php8.3-fpm php8.3-cli php8.3-pgsql \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd \
  php8.3-intl php8.3-bcmath php8.3-gmp composer unzip ufw \
  certbot python3-certbot-nginx
systemctl enable --now nginx postgresql php8.3-fpm
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
install -d -m 0755 /var/www/nasbia
install -d -m 0700 /var/backups/nasbia
printf '%s\n' 'Infrastructure ready. Upload the application before running install.sh.'
