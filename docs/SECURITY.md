# Security & privacy notes

- **Passwords:** Argon2id (bcrypt fallback), rehash on login; sessions invalidated on password change (password marker in session).
- **Sessions:** strict mode, HttpOnly + SameSite=Lax + Secure (HTTPS) cookies, ID rotation every 15 min and on login, idle timeout (admin setting).
- **CSRF:** synchroniser token on every POST (form field or `X-CSRF-Token` header for AJAX).
- **Headers:** strict CSP (`script-src 'self'`, no inline scripts), X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, HSTS on HTTPS.
- **OTP:** 6 digits, HMAC-hashed at rest, 10-minute expiry, 5 attempts, 60 s resend cooldown, 5/hour. Never logged.
- **Login:** throttled per email (5 failures / 15 min) and per IP; generic errors; maintenance mode lets only staff in.
- **Uploads:** size + finfo MIME + getimagesize checks; images re-encoded with GD (strips EXIF/GPS and payloads), random names, stored outside the web root and served by a controller with access checks. Profile photos are members-only.
- **Verification documents:** `storage/private/verifications` (0600), viewable only with the `verifications` permission, every view audited, deleted after the decision.
- **Authorization:** server-side role + permission checks per route and again inside services (owner checks, conversation participants, blocks). Blocked users disappear for each other everywhere.
- **Data minimisation:** students never see emails, phone numbers, full names, dates of birth or exact listing addresses. Contact details are rejected in bios/listings.
- **Secrets:** SMTP password and SSO secret encrypted (AES-256-GCM) in the DB, never re-displayed; config file outside the web root; secrets redacted from logs.
- **SSO from NoidaRankers:** HMAC-SHA256 token, 60 s lifetime, audience-bound, single-use nonce; only existing active staff accounts with the same email.
- **Audit log:** registrations, logins, verifications, moderation decisions, role/status changes, settings changes, data exports, deletions.
