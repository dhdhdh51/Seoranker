# Deploying the website (shared hosting / cPanel / aaPanel)

The website and the Android app are deployed separately. The web app is plain PHP — no Node.js, Composer, Redis, WebSockets or build step is needed on the server. The Android app is built by GitHub Actions and only needs the website's public HTTPS URL.

## Requirements

PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd` (WebP support recommended) · MySQL 8 / MariaDB 10.5+ · HTTPS certificate · cron (or the web-cron fallback in `docs/CRON.md`).

## First install

1. Upload the repository contents **except** `android/`, `tests/` and `.github/` (they're not needed on the server).
2. Point the domain's document root at `public/`. If you can't, the root `.htaccess` forwards requests into `public/` and denies everything else.
3. Create an empty database and user.
4. Run the installer — web: `https://your-domain/install.php`; or CLI:
   ```
   FLATMATES_DB_PASS='…' FLATMATES_ADMIN_PASSWORD='…' php bin/install.php \
     --db-name=… --db-user=… --app-url=https://your-domain --admin-name="Admin" --admin-email=you@example.com
   ```
   The config file (DB password, encryption key) is written **outside** the public folder (`../flatmates-private/config.php`, or `config/config.php` protected by `.htaccess`). Never commit it.
5. Delete `public/install.php`.
6. Add the cron jobs from `docs/CRON.md`.
7. Sign in as the Super Admin and configure **Settings → Email (SMTP)**, **Branding**, **SEO** and (after the first app release) **Android app**.

## Updating an existing site

```
# 1. Back up first
tar czf ~/flatmates-backup-$(date +%F).tgz --exclude=storage/uploads --exclude=storage/private /path/to/site
mysqldump --single-transaction DBNAME | gzip > ~/flatmates-db-$(date +%F).sql.gz     # keep outside the web root

# 2. Upload the new code over the old (keep config/, storage/ and the private config)
# 3. Apply database migrations
php bin/migrate.php --status
php bin/migrate.php
```

Migrations live in `database/migrations/NNN_name.sql`, run once each and are recorded in `schema_migrations`. Fresh installs already include them via `database/schema.sql`.

## Production checklist

- `app.debug` is `false` and `app.env` is `production` in the private config.
- `https://your-domain/config/config.php`, `/storage/…`, `/app/…`, `/.env`, `/install.php` all return 403/404.
- Admin → System health shows no warnings (SMTP, cron, writable folders, HTTPS).
- `storage/private/verifications` is not web-accessible (it isn't served by any route).
- Backups are stored outside the web root and never committed to Git.

## Android download link

After the "Android release APK" workflow publishes a release, fill **Admin → Settings → Android app** with the values from the run summary. The website never invents a download link: with the URL empty, `/app` and the home page say no release is published yet and no QR code is shown.
