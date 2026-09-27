# nasbia.com.ly on DigitalOcean

Target: a new Ubuntu 24.04 Droplet in Frankfurt. Nginx and PHP 8.3 serve
the Laravel application; PostgreSQL listens locally. Libyan Spider retains DNS.

1. Create the approved Droplet with SSH authentication. Run `bootstrap.sh` as root.
2. Build frontend assets with `npm ci --ignore-scripts` and `npm run build` locally.
3. Upload application source and `public/build` to `/var/www/nasbia`.
   Exclude `.env`, `.git`, `vendor`, `node_modules`, local databases, logs,
   cached configuration, sessions, and local uploads unless migration is approved.
4. Run `bash deploy/digitalocean/install.sh` for a **fresh** database only.
   For migration, preserve the existing APP_KEY for encrypted data, restore a
   PostgreSQL backup and uploads, and run only additive migrations. Never run
   `migrate:fresh` or the demo seeder on production.
5. Set an A record for `nasbia.com.ly` to the Droplet IPv4, TTL 300, and a CNAME
   for `www` to `nasbia.com.ly`. Preserve the existing NS and unrelated records.
6. After both names resolve, issue a certificate using `certbot --nginx -d
   nasbia.com.ly -d www.nasbia.com.ly --redirect`. Complete the certificate
   account registration with the owner's email and agreement to its terms.
7. For a fresh installation, run `php artisan admin:create` interactively.
   Use a unique administrator password. Do not seed demo accounts.
8. Verify HTTPS `/up`, `/login`, administrator sign-in, protected portal access,
   file uploads, PDF output, and `certbot renew --dry-run`.

The installation uses synchronous queues, file sessions/cache, and a scheduler
cron job. Email remains logged until an SMTP provider is configured. Configure
VAPID keys for Web Push separately. Arrange off-server database and upload
backups before entering school records; Droplet creation does not establish
an application backup policy.

These scripts are for first deployment, not destructive reinstallation or
automatic upgrades. Back up the production database and uploads before upgrades.
