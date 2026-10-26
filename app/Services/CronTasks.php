<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;

/** Scheduled tasks shared by cron/*.php (CLI) and the authenticated public/cron.php fallback. */
final class CronTasks
{
    public const TASKS = ['mail' => 'Send queued emails (every 1–5 min)', 'cleanup' => 'Expire codes, old sessions, logs (daily)', 'deletions' => 'Anonymise accounts after the deletion grace period (daily)'];

    public function __construct(private App $app)
    {
    }

    /** @return array{status:string, message:string} */
    public function run(string $task): array
    {
        if (!isset(self::TASKS[$task])) {
            return ['status' => 'failed', 'message' => 'Unknown task'];
        }
        $db = $this->app->db();
        $lock = 'flatmates_cron_' . $task;
        if ((int)$db->fetchColumn('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return ['status' => 'skipped', 'message' => 'Already running'];
        }
        try {
            $message = match ($task) {
                'mail' => 'sent=' . $this->app->make(Mailer::class)->processQueue(50),
                'cleanup' => 'removed=' . $this->cleanup(),
                'deletions' => 'anonymised=' . $this->app->make(AccountService::class)->processDeletions(50),
            };
            $status = 'ok';
        } catch (\Throwable $e) {
            $this->app->logger()->error('Cron ' . $task . ' failed: ' . $e->getMessage());
            $status = 'failed';
            $message = mb_substr(\Flatmates\Core\Logger::redactString($e->getMessage()), 0, 400);
        } finally {
            $db->fetchColumn('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        $db->run('INSERT INTO cron_runs (script, last_run_at, status, message) VALUES (?, UTC_TIMESTAMP(), ?, ?) ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), status = VALUES(status), message = VALUES(message)', [$task, $status, $message]);
        return ['status' => $status, 'message' => $message];
    }

    public function cleanup(): int
    {
        $db = $this->app->db();
        $n = 0;
        $n += $db->run('DELETE FROM otp_codes WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY')->rowCount();
        $n += $db->run('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 30 DAY')->rowCount();
        $n += $db->run('DELETE FROM rate_limits WHERE last_hit_at < UTC_TIMESTAMP() - INTERVAL 2 DAY')->rowCount();
        $n += $db->run('DELETE FROM sso_nonces WHERE expires_at < UTC_TIMESTAMP()')->rowCount();
        $n += $db->run('DELETE FROM notifications WHERE is_read = 1 AND created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY')->rowCount();
        $n += $db->run("DELETE FROM mail_queue WHERE status IN ('sent','failed') AND created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY")->rowCount();
        // Unverified sign-ups that never confirmed their email are removed after 14 days.
        $n += $db->run("DELETE FROM users WHERE status = 'pending' AND email_verified_at IS NULL AND role = 'student' AND created_at < UTC_TIMESTAMP() - INTERVAL 14 DAY")->rowCount();
        $sweep = function (string $dir, int $age, string $pattern) use (&$n): void {
            foreach (glob($this->app->config->storagePath($dir) . '/' . $pattern) ?: [] as $f) {
                if (is_file($f) && filemtime($f) < time() - $age) {
                    @unlink($f);
                    $n++;
                }
            }
        };
        $sweep('sessions', 86400 * 2, 'sess_*');
        $sweep('logs', 86400 * 30, '*.log');
        $sweep('mail', 86400 * 7, '*.eml');
        return $n;
    }
}
