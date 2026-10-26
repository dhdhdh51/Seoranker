# Student Flatmate Finder

Mobile-first web app where college students find compatible flatmates (swipe + mutual match + private chat) and flats, shared rooms and vacant beds near campus. Operated by **NoidaRankers** — live at `https://flatmates.noidarankers.in`.

**Stack:** PHP 8.2+ · MySQL 8 / MariaDB 10.5+ (PDO) · Bootstrap 5.3 · vanilla JS (AJAX polling). Runs on shared hosting / cPanel / aaPanel: no Node, Redis, Docker or WebSockets. Composer is optional — PHPMailer is used if `vendor/` exists, otherwise a built-in SMTP client.

## Features
- **Accounts:** register (name, email, password, date of birth, age/terms confirmation) → 6-digit email OTP; login throttling; password reset by OTP; configurable minimum age (default 18).
- **Profiles:** 4-step onboarding (basics, college & budget, lifestyle, photo & privacy), completeness score, per-field visibility (gender/age hidden by default), discoverability toggle, "matches only" profile visibility.
- **Matching:** explainable compatibility score (budget overlap, move-in timing, college, area, cleanliness, sleep, smoking/drinking tolerance, veg-only kitchen, study/social/guests, room type, AC) with reasons and conflicts; swipe deck (drag, buttons, ← / → keys); daily swipe limit; mutual like → match + conversation; unmatch.
- **Filters:** college, budget, move-in date, room type, situation, verified only, non-smokers, vegetarian.
- **Listings:** flat / shared room / vacant bed, up to 8 photos (re-encoded, EXIF stripped), amenities, moderation queue, public or members-only, private address revealed only after an accepted enquiry; search & filters; saved items; enquiries and viewing requests.
- **Chat:** private 1:1 chat between matched students or owner ↔ student after an accepted enquiry; adaptive polling; rate-limited.
- **Verification:** college email OTP (admin-managed domain list per college) or document upload reviewed by a moderator — documents live outside the web root, every view is audited, and the file is deleted after the decision.
- **Safety:** report (users, listings, messages) and block everywhere; serious reasons prioritised; repeated serious reports escalate; listings auto-hidden after N reports.
- **Admin:** dashboard, reports, verifications, listings, users (restrict/suspend, roles & moderator permissions), cities & colleges, settings (branding, SEO, limits, maintenance, SMTP, NoidaRankers SSO), analytics, audit log, system health.
- **SEO:** city and college landing pages, listing pages with JSON-LD, sitemap.xml, robots.txt, canonical URLs.
- **Privacy:** data export (JSON) and account deletion (7-day grace, then anonymised by cron).

## Roles
`guest` (public pages) · `student` · `moderator` (permissions: reports, listings, verifications, users) · `super_admin`. All checks are server-side.

## Install
1. Upload the code; point the document root at `public/` (or keep the root `.htaccess`, which forwards to `public/`).
2. Create an empty database, then either open `/install.php` or run:
   ```
   FLATMATES_DB_PASS=... FLATMATES_ADMIN_PASSWORD=... php bin/install.php --db-name=... --db-user=... \
     --app-url=https://flatmates.example.com --admin-name="Admin" --admin-email=admin@example.com
   ```
   Config is written to `../flatmates-private/config.php` (outside the app) when possible.
3. Delete `public/install.php`, add cron jobs ([docs/CRON.md](docs/CRON.md)), configure SMTP in **Admin → Settings → Email**.

Nginx/LiteSpeed without `.htaccess`: route every non-file request to `public/index.php` and deny `/app`, `/config`, `/storage`, etc.

## Tests
```
php tests/run.php            # Unit + Integration + Http (needs a disposable MySQL DB)
FM_TEST_DB_HOST=127.0.0.1 FM_TEST_DB_USER=... FM_TEST_DB_PASS=... php tests/run.php
```
The Http suite drives the whole app through PHP's built-in server. Test databases must contain "test" in their name (they are dropped).

See also [docs/SECURITY.md](docs/SECURITY.md).
