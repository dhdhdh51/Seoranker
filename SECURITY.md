# Security

## Built-in controls

| Area | Implementation |
|---|---|
| Passwords | `password_hash()` uses Argon2id where available, otherwise bcrypt, and rehashes on login. Passwords need at least 10 characters with letters plus a number or symbol, and may not contain the email name. Changing a password invalidates the user's other sessions. |
| Sessions | Strict mode, HttpOnly, SameSite=Lax, and Secure when served over HTTPS. Session files are stored in the private `storage/sessions` folder. The ID is regenerated on login and every 15 minutes. Sessions expire after an idle timeout (configurable, default 60 min) and an absolute limit of 12 h. |
| Login throttling | Blocks after 5 failures per email or 25 per IP within 15 minutes. Unknown emails return the same generic error, and sign-in attempts are audited. |
| Password reset | Uses a 256-bit single-use token; only its SHA-256 hash is stored, and it expires after 60 minutes. The response is the same whether or not the account exists, and requests are rate-limited. Admins can generate a one-time link if SMTP isn't available. |
| CSRF | Every POST needs a session token. The only exception is the RFC 8058 unsubscribe endpoint, where the 256-bit message token is the authorization. |
| Authorization | `Gate` scopes every query by project. Records you can't access return 404 (not found) and missing permissions return 403. Roles are checked on routes and again inside services. |
| SQL | Every query uses PDO prepared statements, with emulated prepares off and strict SQL mode on. Identifiers are checked against a whitelist. |
| XSS | All output is escaped with `e()`. The Content Security Policy is `script-src 'self'` with no inline scripts, and `frame-ancestors 'none'`. |
| Headers | CSP, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, COOP, HSTS on HTTPS, and `X-Powered-By` removed. |
| SSRF | The crawler allows only http/https and ports 80, 443, 8080 and 8443. It rejects URLs with embedded credentials and blocks loopback, private, link-local, CGNAT, multicast and reserved ranges (including IPv6 ULA, link-local and IPv4-mapped). It also blocks metadata hostnames and `.internal`, `.local` and `.localhost`, plus obfuscated numeric IPs. **Every** DNS answer must be public, the connection is pinned to the validated IP, each redirect is re-validated, and proxies are disabled. Rejections are recorded with a reason. |
| Crawling ethics | Respects robots.txt and Crawl-delay, sends an identifying User-Agent (`/bot.html`), spaces requests to each domain, caps fetches per day, never logs in, and never runs downloaded content. |
| Uploads | Only .csv, .tsv and .txt are accepted, checked by size and by MIME type via finfo. Files containing NUL bytes, PHP or HTML are rejected. Uploads are saved under random names in private storage, deduplicated by SHA-256, and deleted after 30 days. |
| CSV exports | Cells starting with `= + - @ \t \r |` get a leading quote so spreadsheets don't run them as formulas. |
| Secrets | The config file lives outside the web root. SMTP, API and AI keys are encrypted at rest with AES-256-GCM using the config key, and are never displayed or logged. |
| Logs | Passwords, tokens, keys, Bearer values and URL credentials are redacted. Logs live in private storage. |
| Errors | Users see a generic error page. Stack traces appear only with `debug => true`, which is never set in production. |
| Audit | Append-only, SHA-256 hash-chained entries with tamper verification. Optional database triggers are in `database/optional_audit_triggers.sql`. |
| Email | Blocks header injection. Credentials are sent only over TLS or SSL. Every message gets List-Unsubscribe headers. Suppressions and unsubscribes are honored before every send. |

## Deployment checklist
- [ ] Document root points to `public/`. If it can't, the root `.htaccess` must be active.
- [ ] Config is at `../bharatseo-private/config.php`, set to chmod 640 (*Install & upgrade* shows "outside public web root: Yes").
- [ ] `app.env = production` and `app.debug = false`.
- [ ] HTTPS is enabled and `app.url` uses `https://`.
- [ ] `public/install.php` is deleted and `storage/installed.lock` exists.
- [ ] `https://your-domain/storage/`, `/config/`, `/app/`, `/database/schema.sql` and `/.htaccess` return 403 or 404.
- [ ] Cron jobs are configured; the web trigger stays **disabled** unless you have no CLI cron.
- [ ] SMTP is set up with SPF, DKIM and DMARC for the sending domain, and a postal address is filled in under *Settings → Outreach*.
- [ ] Daily email limits and the per-domain cooldown suit your sender reputation.
- [ ] Each Super Admin has their own account; no accounts are shared.
- [ ] Backups are scheduled, and a restore has been tested.
- [ ] Optional: audit triggers installed (if the host grants TRIGGER); `trusted_proxies` set only when you're behind a known proxy.

## Backup & restore
**Recommended:** use cPanel → *Backup* (full or database backup) on a schedule, and keep copies off the server.

**Built-in (works without shell `mysqldump`):**
```
php bin/backup.php                                   # → storage/backups/bharatseo-YYYYmmdd-HHMMSS.sql.gz
php bin/backup.php --restore=storage/backups/FILE.sql.gz --yes   # overwrites Bharat SEO tables
```

**Alternative with mysqldump:**
```
mysqldump --single-transaction --routines --default-character-set=utf8mb4 -u USER -p DBNAME | gzip > bharatseo.sql.gz
gunzip < bharatseo.sql.gz | mysql -u USER -p DBNAME
```

Also back up:
- the private config file — without its `app.key`, encrypted settings can't be decrypted;
- `storage/uploads` if you need the original import files.

Download backups to a safe place. `storage/backups` is denied over the web, but backups should never stay on the server as the only copy.

## Reporting vulnerabilities
Email the agency administrator privately. Please don't open a public issue.
