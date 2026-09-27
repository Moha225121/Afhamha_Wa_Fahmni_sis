#!/usr/bin/env bash
# First installation only, after uploading source and public/build.
# Intentionally stops if a production environment already exists.
set -Eeuo pipefail
cd /var/www/nasbia
test -f artisan
test -f public/build/manifest.json
if test -e .env; then
    echo 'Existing .env found: use a backed-up upgrade process instead.' >&2
    exit 1
fi
install -d -m 0775 storage/app/private storage/app/public storage/framework/cache/data \
  storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
umask 077
db_password=$(openssl rand -hex 32)
runuser -u postgres -- psql -v ON_ERROR_STOP=1 -c "CREATE ROLE nasbia LOGIN PASSWORD '$db_password';"
runuser -u postgres -- createdb --owner=nasbia nasbia
cat > .env <<ENV
APP_NAME=Nasbia
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://nasbia.com.ly
APP_LOCALE=ar
APP_FALLBACK_LOCALE=en
LOG_CHANNEL=daily
LOG_LEVEL=warning
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=nasbia
DB_USERNAME=nasbia
DB_PASSWORD=$db_password
SEED_LOCAL_DEMO=false
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
MAIL_MAILER=log
ENV
unset db_password
umask 022
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan key:generate --force --no-interaction
php artisan migrate --force --no-interaction
php artisan storage:link --no-interaction
php artisan optimize
chown -R root:www-data /var/www/nasbia
chmod 640 .env
chown -R www-data:www-data storage bootstrap/cache
chmod -R u+rwX,g+rwX storage bootstrap/cache
install -m 0644 deploy/digitalocean/nginx.conf /etc/nginx/sites-available/nasbia
ln -s /etc/nginx/sites-available/nasbia /etc/nginx/sites-enabled/nasbia
nginx -t
systemctl reload nginx
printf '%s\n' '* * * * * www-data cd /var/www/nasbia && /usr/bin/php artisan schedule:run >> /var/www/nasbia/storage/logs/scheduler.log 2>&1' > /etc/cron.d/nasbia
chmod 644 /etc/cron.d/nasbia
printf '%s\n' 'Install complete. Configure DNS, issue HTTPS certificate, then create the administrator with php artisan admin:create.'
