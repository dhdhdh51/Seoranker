# Feature test report

Run: `php tests/run.php` on PHP 8.2.35 + MariaDB 10.11.9 (October 2026).

**Result: 110 tests, 110 passed, 0 failed, 0 skipped, 1378 assertions in 28.9s**

Integration and HTTP tests run against a real database and PHP's built-in web server, not mocks. Only HTTP responses from third-party sites are simulated (local fixtures).

## Acceptance criteria → evidence

| Criterion | Verified by |
|---|---|
| Login, logout and password reset | AuthTest (login, disabled account, single-use expiring reset token stored as hash); HttpSmokeTest 01/02/10 (login page, wrong password, CSRF on login, logout destroys session) |
| Role-based permissions | AuthorizationTest (admin/manager/viewer/client abilities); HttpSmokeTest 04 (403 on admin pages for client & manager) |
| Cross-client isolation | AuthorizationTest::ClientIsolationAcrossServices; HttpSmokeTest 05 (direct URL / POST manipulation → 404, data unchanged); 07 (exports scoped) |
| Project CRUD | ProjectDiscoveryTest (create/update/unique domain per client/target-page validation/status pause cancels jobs); HttpSmokeTest 06 (create via UI) |
| CSV import/export | ProjectDiscoveryTest (UTF-16 tab Ahrefs export, chunked, duplicate file rejected, upload validation); SystemTest + HttpSmokeTest 07 (CSV exports, BOM, formula neutralisation) |
| Duplicate prospect prevention | ProjectDiscoveryTest (https/www/slash/utm variants → 1 record; re-submit idempotent); UrlTest dedupe key |
| Quality score calculations | QualityScorerTest (weights=100, components sum, Unknown handling, bands, critical flags force hold); EvaluationApprovalTest |
| URL validation and SSRF protection | SafeUrlValidatorTest (private/loopback/metadata/IPv6/obfuscated IPs/DNS answers); HttpClientTest (redirect re-validation, IP pinning, robots, rate limit) |
| Approval and rejection workflows | EvaluationApprovalTest (manual hold, release with reason, reject reason, viewer/foreign denied); OutreachFlowTest (manager+client policy, revision-bound approvals, rejection → draft); HttpSmokeTest 08 |
| Email draft creation | OutreachWriterTest (all templates pass guard, no invented names, broken-link evidence rule); OutreachFlowTest (idempotent drafts, revisions, guard on edits) |
| SMTP failure handling | OutreachFlowTest::SmtpFailureIsRetriedThenFailedWithoutCrashing (real SmtpTransport to closed port → deferred → failed + notification; 550 → immediate fail; secrets not in error) |
| Queue retry and locking | QueueTest (unique keys, exclusive reservation, 60s→120s backoff, max attempts → failed_jobs, stale lock recovery, pause, retry failed); SystemTest cron lock |
| Backlink detection and history | BacklinkParsingTest (local fixture: anchor, rel, hidden, base href, no JS); BacklinkMonitorTest (full lifecycle + 7 history rows) |
| Temporary-error retry behaviour | BacklinkMonitorTest (single 503 ≠ lost; spaced confirmations; too-soon re-check not counted; repeated errors → broken) |
| Cron health reporting | SystemTest (never-run/stale warnings, failed runs recorded, all 5 CLI scripts exit 0); HttpSmokeTest 03 (dashboard warning), 09 (web trigger auth + rate limit) |
| Mobile responsiveness | HttpSmokeTest asserts viewport meta + balanced markup on every page; headless Chromium at 390×844: no horizontal overflow, off-canvas nav works, no JS console errors (manual run, see below) |
| Database backup and restore | SystemTest::BackupAndRestoreRoundTrip (quotes/semicolons/newlines, audit chain identical) |
| Secure installer lock | SystemTest::InstallerCreatesAdminAndLocks (no default users, production config, second run refused, config alone locks); HttpSmokeTest 09 (install.php → 403) |
| No PHP warnings or fatal errors | Runner converts every warning/notice into a failure; HTTP server runs with debug=false and the log must contain no 'PHP:' or 'ERROR' after crawling ~70 pages as 4 roles |

## Manual / visual checks

- Headless Chromium screenshots at 1440×900 (light and dark) and 390×844 (mobile) for dashboard, prospects, prospect detail, assessment, approvals, draft editor, backlinks, queue health, report, discovery and project pages. No console errors and no horizontal overflow on mobile.
- The visual review found one real bug: an unclosed `<div>` on the prospect detail page. It is fixed, and the HTTP suite now asserts balanced `<div>` tags on every page.

## Hosting-dependent (not verifiable in CI)

- Real SMTP delivery: only the connection failure path is tested. Use *Settings → Send SMTP test*.
- Real outbound crawling: the network layer is tested with a scripted transport. Real cURL behaviour depends on the host's firewall.
- cPanel cron scheduling and Apache `.htaccess` enforcement. Check them with the deployment checklist in SECURITY.md.
- Optional APIs (Brave, SearXNG, Safe Browsing, local model) need your own credentials.

## All tests

### Unit › BacklinkParsingTest
- ✅ Parser extracts metadata links and contacts
- ✅ Detects active link with anchor and no rel
- ✅ Detects changed anchor
- ✅ Detects nofollow sponsored rel
- ✅ Not pointing and missing
- ✅ Error classification
- ✅ Redirected referring page

### Unit › OutreachWriterTest
- ✅ Every template is clean and never invents names
- ✅ Uses publicly provided name only
- ✅ Broken link template requires evidence
- ✅ Content guard blocks fabrication and spam claims
- ✅ Local model response parsing
- ✅ Prospect workflow transitions

### Unit › QualityScorerTest
- ✅ Weights sum to100 and components add up
- ✅ Relevant editorial page scores high
- ✅ Bands
- ✅ Missing evidence is unknown and earns nothing
- ✅ Critical flags force hold even with high score
- ✅ Thin irrelevant paid and anchor flags
- ✅ Guest post without guidelines is flagged
- ✅ Excessive outbound links
- ✅ Natural anchors are not flagged

### Unit › RobotsTxtTest
- ✅ Specific group longest match and wildcards
- ✅ Empty disallow and rfc status handling

### Unit › SafeUrlValidatorTest
- ✅ Allows public hosts
- ✅ Blocks loopback private link local and metadata
- ✅ Blocks dns answers pointing internally
- ✅ Blocks obfuscated numeric hosts
- ✅ Blocks bad schemes ports and credentials
- ✅ Cidr math

### Unit › SecurityPrimitivesTest
- ✅ Csv formula injection is neutralised
- ✅ Csv header mapping for competitor exports
- ✅ Crypto round trip and tamper detection
- ✅ Password policy and hashing
- ✅ Log redaction
- ✅ Mail header injection is rejected

### Unit › UrlTest
- ✅ Normalize lowercases host strips tracking and default port
- ✅ Normalize rejects non http and garbage
- ✅ Idn hosts are converted to punycode
- ✅ Dedupe key ignores scheme www trailing slash and tracking
- ✅ Resolve relative references
- ✅ Domain helpers

### Integration › AuthTest
- ✅ Login success and failure
- ✅ Disabled account cannot sign in
- ✅ Login throttling after repeated failures
- ✅ Password reset is single use and expires

### Integration › AuthorizationTest
- ✅ Super admin sees everything
- ✅ Manager only assigned projects
- ✅ Viewer member cannot manage
- ✅ Client isolation across services
- ✅ Client export permission
- ✅ Archived project hidden from client
- ✅ Disabled client account loses access

### Integration › BacklinkMonitorTest
- ✅ Duplicate tracking prevented
- ✅ Full lifecycle with retry and confirmation rules
- ✅ Repeated temporary errors eventually mark broken
- ✅ Candidate never observed stays unverified
- ✅ Attribute and target changes
- ✅ Due checks are enqueued idempotently

### Integration › EvaluationApprovalTest
- ✅ Evaluation scores and moves to review
- ✅ Temporary fetch error is retried not scored
- ✅ Critical risk forces manual hold and blocks approval
- ✅ Rejection workflow and permissions
- ✅ Manual submission records unique external id

### Integration › HttpClientTest
- ✅ Redirect to internal address is blocked
- ✅ Connections are pinned to validated ip
- ✅ Safe redirects are followed within limit
- ✅ Robots txt is respected
- ✅ Per domain rate limit and content type errors

### Integration › OutreachFlowTest
- ✅ Draft creation is idempotent and requires approved prospect
- ✅ Edits are guarded versioned and invalidate approval
- ✅ Manager and client policy requires both approvals
- ✅ Rejection returns to draft
- ✅ Sender sends approved with unsubscribe and protections
- ✅ Daily limit pause and smtp not configured
- ✅ Smtp failure is retried then failed without crashing
- ✅ Interrupted send is never auto resent
- ✅ Follow up drafts after delay and stop on reply

### Integration › ProjectDiscoveryTest
- ✅ Project crud with validation and unique domain
- ✅ Manager cannot create project for unassigned client
- ✅ Ownership verification methods
- ✅ Manual discovery prevents duplicates and honours exclusions
- ✅ Competitor csv import utf16 tab delimited in chunks
- ✅ Upload validation rejects non csv
- ✅ Public page extraction respects result safety

### Integration › QueueTest
- ✅ Idempotent enqueue with unique key
- ✅ Reservation is exclusive
- ✅ Retry with exponential backoff then failed job archive
- ✅ Permanent failure from handler goes straight to failed
- ✅ Stale locks are recovered
- ✅ Pause stops processing immediately
- ✅ Backoff caps and jitter

### Integration › SystemTest
- ✅ Cron health reports honestly
- ✅ Cron lock prevents overlap
- ✅ Cron scripts run from cli
- ✅ Audit chain detects tampering
- ✅ Secrets are encrypted at rest
- ✅ Reports use real records and exports are safe
- ✅ Backup and restore round trip
- ✅ Installer creates admin and locks

### Http › HttpSmokeTest
- ✅ Security headers and guest pages
- ✅ Login failure and csrf
- ✅ All pages render for super admin
- ✅ Manager and client permissions
- ✅ Cross client url manipulation is blocked
- ✅ Forms work end to end with csrf
- ✅ Exports and printable report
- ✅ Approval through ui
- ✅ Unsubscribe installer lock and cron trigger
- ✅ Logout and no php warnings logged
