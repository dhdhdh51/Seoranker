# Cron setup (cPanel / aPanel)

Automation runs only through these scripts. If none of them has run recently, the dashboard shows a red warning: **"Automation is NOT active"**. *Cron & queue health* (Super Admin) shows when each script last ran successfully.

| Script | Purpose | Suggested schedule |
|---|---|---|
| `cron/process_queue.php` | Runs queued jobs: evaluations, imports, searches, public-page extraction, broken-link scans, backlink checks and auto-drafts | every 5 min (every 1–2 min if allowed) |
| `cron/send_approved_outreach.php` | Sends **approved** emails within the configured limits; drafts follow-ups that are due (these still need approval) | every 10 min |
| `cron/check_backlinks.php` | Queues backlink checks that are due and processes them | hourly |
| `cron/discover.php` | Makes sure every prospect gets evaluated, refreshes stale scores, and runs scheduled search discovery for projects that opted in | every 6 h |
| `cron/cleanup.php` | Removes expired jobs, tokens, sessions, old logs and import files (audit logs are never deleted) | daily |

## Rules every script follows
- **Bounded:** each run stops after about 50 s or a set number of jobs.
- **No overlap:** a file lock prevents two copies of the same script running at once. An overlapping run is recorded as *skipped*.
- **Idempotent and safe to retry:** jobs are reserved atomically, and unique keys prevent duplicate work. Retries use exponential backoff (60 s, 120 s, 240 s … up to 6 h). After the maximum attempts, a job moves to the *Failed jobs* screen.
- **Pause respected:** *Pause all jobs* stops every script at once, including email sending.

## cPanel
*cPanel → Advanced → Cron Jobs → Add New Cron Job*. Use the PHP **CLI** binary, which is often `/usr/local/bin/php` or `/opt/cpanel/ea-php82/root/usr/bin/php`. The *Cron & queue* page prints the exact paths for your server.

```
*/5 * * * *  /usr/local/bin/php /home/USER/bharatseo/cron/process_queue.php >/dev/null 2>&1
*/10 * * * * /usr/local/bin/php /home/USER/bharatseo/cron/send_approved_outreach.php >/dev/null 2>&1
15 * * * *   /usr/local/bin/php /home/USER/bharatseo/cron/check_backlinks.php >/dev/null 2>&1
30 */6 * * * /usr/local/bin/php /home/USER/bharatseo/cron/discover.php >/dev/null 2>&1
45 3 * * *   /usr/local/bin/php /home/USER/bharatseo/cron/cleanup.php >/dev/null 2>&1
```

If the config file isn't in one of the default locations, add `BHARATSEO_CONFIG=/path/to/config.php` at the start of each command.

## aPanel (and other panels)
Open *Cron* / *Scheduled tasks*, choose the type *Shell script*, and use the same commands. Make sure you pick the PHP 8.2+ CLI binary.

## Testing a script manually
```
/usr/local/bin/php /home/USER/bharatseo/cron/process_queue.php
# → [2026-10-23T10:00:00+00:00] process_queue: completed (3) processed=3 retried=0 failed=0
```
The exit code is non-zero when a run fails. Details are written to `storage/logs/app-YYYY-MM-DD.log`.

The cron scripts refuse to run over HTTP. Only the authenticated `public/cron.php` described below can trigger tasks from the web.

## Fallback: web trigger (only if your host has NO CLI cron)
1. In the private config file (`bharatseo-private/config.php`), set:
   ```php
   'cron' => ['web_trigger_enabled' => true, 'web_trigger_secret' => '<64 random hex chars>'],
   ```
   Generate a secret with `php -r "echo bin2hex(random_bytes(32));"`. The installer already generates one.
2. Use an external scheduler (for example cron-job.org or UptimeRobot) to call:
   ```
   curl -fsS -H "X-Bharat-Cron-Key: <secret>" "https://seo.example.in/cron.php?task=process_queue"
   ```
   `task` can be any of: `process_queue`, `send_approved_outreach`, `check_backlinks`, `discover`, `cleanup`.

How the web trigger is protected:
- **Secret:** compared in constant time; never stored in the database or under `public/`.
- **Rate limits:**
  - each task can run at most once every 55 s;
  - after 10 wrong keys in an hour, that IP is blocked for the rest of the hour;
  - each run is limited to 25 s.
- **Off by default:** while disabled, the endpoint returns 404.
- **Header preferred:** a `?key=` query parameter also works, but URLs often end up in access logs, so prefer the header.
