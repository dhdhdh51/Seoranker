# Bharat SEO — Backlink Automation Platform

A multi-client platform for agencies to research, evaluate, reach out about and monitor backlinks. It runs on ordinary **cPanel / aPanel shared hosting** with PHP 8.2+, MySQL 8 / MariaDB 10.5+, PDO, Bootstrap 5 and vanilla JS. It doesn't need Node.js, Redis, Docker, a VPS, Composer, or any paid API.

It's built to stay within responsible link-building rules:

- It never scrapes search result pages and never bypasses CAPTCHAs, logins or robots.txt.
- It never creates accounts, never posts comments, and never sends email without approval.
- It never guarantees rankings or dofollow links.

## Contents
1. [Features](#features)
2. [Folder structure](#folder-structure)
3. [Installation](#installation)
4. [Roles & workflow](#roles--workflow)
5. [Integrations: working vs optional](#integrations-working-vs-optional)
6. [Testing](#testing) and [feature test report](docs/TEST_REPORT.md)
7. [Known limitations](#known-limitations)

Related guides: [CRON_SETUP.md](CRON_SETUP.md) · [SECURITY.md](SECURITY.md) · [UPGRADE.md](UPGRADE.md)

## Features

| Module | What it does |
|---|---|
| Multi-client RBAC | Three roles: Super Admin, SEO Manager (works on assigned projects as *manager* or *viewer*) and Client (sees only its own client's projects, with optional approve/export rights). Access is checked on the server in the query and service layers. Records you aren't allowed to see return **404**, so their existence isn't leaked. |
| Projects | Domain is normalized and unique per client. Each project stores keywords/topics, target pages, brand and natural anchors, outreach identity, exclusions and an approval policy. Ownership is verified by file, meta tag or DNS TXT. Verification is required before any feature that crawls the client's own site (sitemap import). |
| Discovery | Sources: manual URLs, CSV prospect lists, public datasets, research-tool lists, and competitor backlink exports (Ahrefs, Semrush, Moz, Majestic, GSC; UTF-8 or UTF-16; comma, semicolon or tab). Also: links extracted from public pages, and optional search APIs. Duplicates are caught with a scheme-, www-, slash- and tracking-insensitive key. Large imports run in chunks. |
| Crawler | cURL-based and SSRF-safe: every redirect hop is re-validated, the resolved IP is pinned, and only HTTP/HTTPS is allowed. It has size and time limits, validates content type, respects robots.txt (including Crawl-delay), enforces a per-domain interval and a daily fetch cap, and retries with backoff. It parses HTML with DOMDocument and never runs JavaScript. |
| Quality & risk engine | Transparent 0–100 score made of relevance (30), editorial quality (25), trust (20), technical health (15) and placement (10). Every point is explained. Missing evidence is marked **Unknown** and earns no points. Bands are 80+, 60–79 and below 60. Critical flags (hidden links, spam vocabulary, cross-domain redirects, link farms, malware, excluded domains, unsafe URLs) force a **manual hold**. |
| Outreach | Offline template writer (the default "AI"), with an optional OpenAI-compatible local model. Covers guest-post, expert, resource, broken-link, unlinked-mention, partner and general pitches, plus follow-ups. Offers subject alternatives, keeps revisions, and supports preview, copy to clipboard and CSV export. A content guard blocks invented names, past relationships, approvals, metrics, guarantees, dofollow requests and placeholders. |
| Approvals | Workflow: Discovered → Evaluated → Needs Review → Approved → Submitted / Outreach Sent → Verified / Rejected / Failed. Outreach policy can be manager, client, or both. Approvals are tied to a revision, so editing a message invalidates them. Every decision goes into a hash-chained audit log. |
| Sending | Sends only approved messages. Enforces a global pause, a daily limit (global and per project), a per-domain cooldown, duplicate-send protection, a suppression list, and one-click unsubscribe (RFC 8058) with a footer. SMTP failures are retried and then marked failed. |
| Backlink monitor | Tracks status: Active, Lost, Redirected, Broken, Not pointing, Changed anchor, Nofollow/Sponsored/UGC, Unverified, Temporarily unavailable. "Backlink confirmed" and "Backlink lost" are tracked separately as a lifecycle. A link is never marked lost after a single error: negative results must repeat on checks spaced apart. Full check history is kept. |
| Reports | Printable HTML report and CSV exports (prospects, backlinks, history, drafts, report summary) built from live records, with the generation date. Cells that could act as spreadsheet formulas are neutralized. |
| Ops | MySQL job queue with locking, retries, failed-job review and a pause button. Five cron scripts, plus a web-trigger fallback. Honest health warnings, installer lock, migrations, and a pure-PHP backup/restore. |

## Folder structure

```
app/            PHP source (namespace BharatSeo\)
  Core/         App container, Config, Database (PDO), ErrorHandler, Logger
  Security/     Auth, Gate (authorization), Session, Csrf, Crypto, RateLimiter
  Crawler/      Url, SafeUrlValidator (SSRF), HttpClient, RobotsTxt, HtmlParser
  Services/     Projects, Discovery, Evaluation, QualityScorer, Outreach*, Approval,
                BacklinkMonitor, Reports, Queue, Cron, Mail/, Installer, Backup, Migrator …
  Jobs/         Queue job handlers (idempotent)
  Controllers/  HTTP controllers;  Http/ Kernel, Router, Request, Response, View
  routes.php
views/          PHP templates (escaped with e())
public/         WEB ROOT: index.php, install.php, cron.php, assets/ (Bootstrap vendored)
cron/           discover.php, process_queue.php, send_approved_outreach.php, check_backlinks.php, cleanup.php
bin/            install.php, migrate.php, backup.php, seed_demo.php (CLI only)
database/       schema.sql, migrations/, optional_audit_triggers.sql
config/         config.example.php (the real config is written OUTSIDE the app folder when possible)
storage/        logs, uploads, exports, sessions, locks, mail, backups (denied by .htaccess)
tests/          Unit, Integration and Http suites + fixtures (php tests/run.php)
```

## Installation

**Requirements:** PHP 8.2+ with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `dom`, `libxml`, `fileinfo` and `json`. `intl` is recommended for international domain names. You also need MySQL 8 or MariaDB 10.5+ and Apache or LiteSpeed with `mod_rewrite`.

1. **Create a database.** In cPanel → *MySQL Databases*, create an empty database and a user, and give the user ALL PRIVILEGES on that database.
2. **Upload the files.** Recommended layout:
   ```
   /home/USER/bharatseo/            ← this repository
   /home/USER/bharatseo-private/    ← created by the installer (config.php lives here)
   ```
   Point the domain or sub-domain **document root at `/home/USER/bharatseo/public`**. In cPanel this is under *Domains → Manage → Document Root*.

   If you can't change the document root, upload everything into the web root instead. The root `.htaccess` sends requests to `public/` and denies `app/`, `config/`, `storage/` and the other private folders.
3. **Run the installer.** Open `https://your-domain/install.php`. It checks the PHP version, extensions and writable folders, then tests the database connection. Next it creates the tables and the first Super Admin (there are no default accounts) and writes the config file outside the web root. Finally it locks itself with `storage/installed.lock` and can delete `install.php`.

   CLI alternative:
   ```
   BSEO_DB_PASS=… BSEO_ADMIN_PASSWORD=… php bin/install.php --db-host=localhost --db-name=… --db-user=… \
       --app-url=https://seo.example.in --admin-name="Your Name" --admin-email=you@example.in
   ```
4. **Add the cron jobs** from [CRON_SETUP.md](CRON_SETUP.md). Until you do, the dashboard and the *Cron & queue* page show *"Automation is NOT active"*.
5. **Configure the rest** under *Settings*: SMTP, limits and optional integrations. Then create clients, users and projects.

For demo or development use only: set `'env' => 'development'` and `'demo_mode' => true` in the config, then run `php bin/seed_demo.php`. Every demo record is prefixed `[DEMO]` and uses reserved `example.*` domains.

## Roles & workflow

1. A **Super Admin** creates clients, users and projects, and assigns SEO managers to projects. They can also attach client users with *can approve* and *can export* rights.
2. A **Manager** adds opportunities through discovery. A queue job fetches each one, scores it, and moves it to *Needs review*. Low scores stay *Evaluated*, and critical flags put the item on hold.
3. The manager approves or rejects prospects in the **Approval queue**. A held item can only be approved after a documented release.
4. For each approved prospect, the manager either:
   - creates a **draft** in a campaign, edits it, and submits it for approval under the project's policy, or
   - for directories and citations, opens the **official submission page**, submits manually, and records the submission.
5. `send_approved_outreach.php` sends approved messages within the limits. Replies are recorded manually.
6. **Track backlink** creates a monitor record. When the crawler sees the link, the record becomes *Backlink confirmed* and the prospect becomes *Verified*. A link is marked lost only after repeated, spaced negative checks.

## Integrations: working vs optional

**Working out of the box (no paid services):**
- Manual, CSV, dataset, competitor-export and public-page discovery
- Crawler and SSRF protection, robots.txt handling, quality scoring
- Template-based outreach writing, approvals, the suppression list and unsubscribe
- Backlink monitoring, reports and CSV exports
- Job queue, cron scripts and the web-trigger fallback
- Audit log, backup and restore, migrations
- SMTP sending through any mailbox you configure (cPanel email, a transactional provider, etc.)

**Optional (need your own credentials or service; the platform works without them):**

| Integration | Notes |
|---|---|
| Brave Search API | Needs an API key. The free or paid tier is your choice, and a daily quota setting protects it. |
| SearXNG | Only an instance you run or are authorized to use, with JSON output enabled. |
| Google Custom Search JSON API | Legacy. Google has closed it to new customers and discontinues it on **1 Jan 2027**. It's supported only for existing keys. |
| Google Safe Browsing v4 Lookup | Needs a free API key. Without it, the malware check shows *Unknown*. |
| Local AI model | Any OpenAI-compatible `/v1/chat/completions` endpoint, such as Ollama, llama.cpp, LM Studio or vLLM. It has a timeout, its output is validated, and the template is used on any failure. |
| Authorized directory submission APIs | Not shipped. No universal, legitimate API exists, so directories are submitted manually on their official pages. |

## Testing

```
php tests/run.php            # Unit + Integration + Http (Integration/Http need a disposable DB)
php tests/run.php Unit
BSEO_TEST_DB_HOST=127.0.0.1 BSEO_TEST_DB_USER=… BSEO_TEST_DB_PASS=… php tests/run.php Integration
```

Integration and HTTP tests **drop and recreate** the databases `bseo_test` and `bseo_http_test` (they refuse to run on any database whose name doesn't contain "test"). The HTTP suite starts PHP's built-in server with `tests/dev-router.php`. Current result: **110 tests, 0 failures**. The full list is in [docs/TEST_REPORT.md](docs/TEST_REPORT.md).

## Known limitations

- **Hosting:**
  - Background work runs only while cron runs. Each run is capped at about 50 s, so throughput is roughly tens of fetches per cron run. Hosts that forbid outgoing HTTP or SMTP will block crawling or sending; the UI reports this as errors rather than as success.
  - `gethostbynamel` and DNS TXT verification depend on the host's resolver. The IP pinning that prevents DNS rebinding needs cURL 7.21.3 or newer (`CURLOPT_RESOLVE`).
- **Backlink data:** Only pages you add or discover are monitored. The app has no access to any search engine's backlink index and doesn't claim to. "Confirmed" means the link was seen in fetched HTML. Pages that inject links with JavaScript can't be observed, because no JavaScript is executed.
- **Scores are heuristics.** There's no domain-age, traffic or authority data unless you add an integration. Those fields are shown as *Unknown*.
- **Replies:** There's no IMAP reading, so reply and bounce status is recorded manually. A bounce automatically adds the address to the suppression list.
- **Hidden-link detection** only catches inline styles and attributes, not rules in external CSS.
- **Unsubscribe** is global across the agency. Per-project suppression is available for manual entries.
- **Backup:** The pure-PHP backup suits small and medium databases. For large ones, use cPanel Backup or `mysqldump`.
- **Optional audit triggers** (`database/optional_audit_triggers.sql`) need the TRIGGER privilege, which many shared hosts don't grant.
