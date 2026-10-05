#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/nds}"
REPO_URL="${REPO_URL:-https://github.com/Moha225121/nasr-al-daem-school.git}"
BRANCH="${BRANCH:-main}"
LOCK_FILE="/var/lock/nds-deploy.lock"

exec 9>"$LOCK_FILE"
flock -n 9 || exit 0
cd "$APP_DIR"
test -f artisan
test -f .env

if [ ! -d .git ]; then
  git init
  git remote add origin "$REPO_URL"
  git add -A
  git -c user.name='nds-deployer' -c user.email='root@localhost' commit -m 'server baseline' >/dev/null 2>&1 || true
fi
git remote set-url origin "$REPO_URL"
git fetch --quiet origin "$BRANCH"
remote_sha="$(git rev-parse "origin/$BRANCH")"
local_sha="$(git rev-parse HEAD 2>/dev/null || true)"
[ "$remote_sha" = "$local_sha" ] && exit 0

backup="/var/backups/nds/$(date +%Y%m%d%H%M%S)"
install -d -m 0750 "$backup"
cp .env "$backup/.env"
if [ -d storage/app ]; then cp -a storage/app "$backup/storage-app"; fi

git checkout -B "$BRANCH" "origin/$BRANCH"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
if [ -f package-lock.json ]; then npm ci --ignore-scripts; npm run build; fi
php artisan migrate --force --no-interaction
php artisan storage:link --no-interaction || true
php artisan optimize
chown -R www-data:www-data storage bootstrap/cache
chmod -R u+rwX,g+rwX storage bootstrap/cache
systemctl reload php8.3-fpm || true
systemctl reload nginx || true
echo "Deployed $remote_sha"
