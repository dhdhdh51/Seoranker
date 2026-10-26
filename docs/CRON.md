# Cron jobs

| Task | Schedule | Command |
|---|---|---|
| Send queued emails (OTP retries etc.) | every 1–5 min | `php /path/to/app/cron/mail.php` |
| Cleanup (expired codes, rate limits, old sessions/logs, unverified sign-ups after 14 days) | daily | `php /path/to/app/cron/cleanup.php` |
| Account deletions (anonymise after 7-day grace) | daily | `php /path/to/app/cron/deletions.php` |

Each task takes a database lock, so overlapping runs are skipped. Status is shown in **Admin → System health**.

## No CLI cron? Web trigger fallback
In the private config set:
```php
'cron' => ['web_trigger_enabled' => true, 'web_trigger_secret' => '<64 random hex chars>'],
```
Then call (from any external cron service):
```
curl -fsS -H "X-Cron-Key: <secret>" "https://flatmates.example.com/cron.php?task=mail"
```
Wrong keys are throttled per IP; each task runs at most once per 50 seconds.
