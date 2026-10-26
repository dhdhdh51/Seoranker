# Student Flatmate Finder

Mobile-first web app (plus an Android app) where college students find compatible flatmates (swipe + mutual match + private chat) and flats, shared rooms and vacant beds near campus. Operated by **NoidaRankers** — live at `https://flatmates.noidarankers.in`.

| Part | Where | Docs |
|---|---|---|
| Website (PHP/MySQL, shared hosting) | repo root (`app/`, `public/`, `views/` …) | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |
| Android app (Kotlin WebView shell) | `android/` | [docs/ANDROID.md](docs/ANDROID.md) |
| Debug APK build | `.github/workflows/android-apk.yml` | artifact `student-flatmate-android-debug` |
| Signed release + GitHub Release | `.github/workflows/android-release.yml` | tag `flatmates-android-vX.Y.Z` |

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
- **Trust badges:** *Email Verified*, *College Email Verified*, *Student ID Reviewed*, *Listing Reviewed* — each shown only when backed by a real record, with an explanation of what it does and doesn't prove (Safety centre). "Listing Reviewed" is cleared whenever the owner edits the listing or its photos.
- **Design system:** CSS design tokens (navy `#142B4A`, trust blue `#3478F6`, off-white `#F7F9FC`), dark mode, accessible focus states, responsive WebP photography (CC0, see [docs/CREDITS.md](docs/CREDITS.md)), loading/empty states and confirmation dialogs.
- **Android app download:** `/app` page, home-page band and footer link driven by admin settings (URL, version, date, SHA-256), an optional QR code that encodes exactly the configured URL (built-in, dependency-free generator), and `/.well-known/assetlinks.json` for App Links. Nothing is shown as downloadable until a real release URL is configured.
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
4. When updating an existing install, run `php bin/migrate.php`. Full guide: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

Nginx/LiteSpeed without `.htaccess`: route every non-file request to `public/index.php` and deny `/app`, `/config`, `/storage`, etc.

## Tests
```
php tests/run.php            # Unit + Integration + Http (needs a disposable MySQL DB)
FM_TEST_DB_HOST=127.0.0.1 FM_TEST_DB_USER=... FM_TEST_DB_PASS=... php tests/run.php
```
The Http suite drives the whole app through PHP's built-in server. Test databases must contain "test" in their name (they are dropped).

Android: `cd android && ./gradlew testDebugUnitTest lintDebug assembleDebug`.

See also [docs/SECURITY.md](docs/SECURITY.md), [docs/ANDROID.md](docs/ANDROID.md), [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md).

## Never commit

Private config / `.env` files, database credentials or dumps, SMTP passwords, Android keystores (`*.jks`), verification documents, real student data or anything under `storage/`. These are covered by `.gitignore`; use the clearly-labelled demo data from tests only.
