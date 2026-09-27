# Deployment status — 2026-09-09

- Live login: https://nasbia.com.ly/login
- DigitalOcean Droplet: `599105921`, `nasbia-production`, Frankfurt FRA1.
- IPv4: `207.154.245.121`; Ubuntu 24.04; 2 vCPU, 2 GB RAM, 60 GB disk.
- Dashboard estimated server cost: $18/month. Automated Droplet backups are not enabled.
- Application directory: `/var/www/nasbia`.
- Fresh PostgreSQL database `nasbia`; no local school records/uploads migrated.
- Administrator: `admin@nasbia.com.ly`. The generated password is held in the
  private local file `tmp/nasbia-admin-credentials.json`, excluded from Git.
- SSH key on the owner's computer: `C:\Users\PIXEL\.ssh\nasbia_deploy_v2`.
- Libyan Spider: apex A record to the IPv4 above and www CNAME to the apex, TTL 300.
- HTTPS active for apex and www; HTTP redirects to HTTPS; Certbot automatic renewal enabled and dry-run renewal succeeded.
- Nginx, PHP-FPM, and PostgreSQL active. UFW permits SSH, HTTP, and HTTPS.
- Laravel production mode, debug disabled, secure session cookies, additive migrations applied.
- Scheduler runs every minute as www-data. Queues are synchronous.
- Verified: health endpoint, styled login page, administrator login to dashboard,
  protected dashboard redirect when logged out, and blocked `.env` access.
- SMTP, Web Push keys, and off-server backups still need owner-specific configuration.

DigitalOcean's Ubuntu package mirror returned an index checksum mismatch during
installation. The server uses the official `archive.ubuntu.com` mirror instead;
the original source configuration is saved at `/root/ubuntu.sources.original`.

Release archive SHA256:
`dc666ce8331a6b23544274ae3828b6e937428afb51784f32dcc3faa12e014e1e`.
