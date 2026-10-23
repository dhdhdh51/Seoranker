<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;

/**
 * Cron heartbeat + health reporting and overlap protection with file locks.
 */
final class CronService
{
    /** script => expected max minutes between runs (multiplied by the configured interval factor) */
    public const SCRIPTS = [
        'process_queue' => ['label' => 'Process job queue', 'every' => 1],
        'discover' => ['label' => 'Scheduled discovery', 'every' => 12],
        'send_approved_outreach' => ['label' => 'Send approved outreach', 'every' => 2],
        'check_backlinks' => ['label' => 'Backlink checks', 'every' => 12],
        'cleanup' => ['label' => 'Cleanup', 'every' => 288],
    ];

    private Database $db;
    /** @var resource|null */
    private $lock = null;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    /** Acquire a non-blocking exclusive lock; returns false if another run is active. */
    public function lock(string $script): bool
    {
        $dir = $this->app->config->storagePath('locks');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $fh = @fopen($dir . '/' . preg_replace('/[^a-z_]/', '', $script) . '.lock', 'c');
        if ($fh === false) {
            return true; // cannot lock (read-only FS); DB job reservation still prevents double work
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return false;
        }
        $this->lock = $fh;
        return true;
    }

    public function unlock(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock = null;
    }

    public function start(string $script, string $trigger = 'cli'): int
    {
        return $this->db->insert('cron_runs', ['script' => $script, 'trigger_type' => $trigger, 'status' => 'running']);
    }

    public function finish(int $runId, string $status, int $processed = 0, ?string $message = null): void
    {
        $this->db->run('UPDATE cron_runs SET status = ?, processed = ?, message = ?, finished_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $processed, $message !== null ? mb_substr($message, 0, 1000) : null, $runId]);
    }

    /**
     * Run a cron task with locking + heartbeat. $fn returns [processed, message].
     * @param callable():array{0:int,1:?string} $fn
     */
    public function run(string $script, string $trigger, callable $fn): array
    {
        if (!$this->lock($script)) {
            $id = $this->start($script, $trigger);
            $this->finish($id, 'skipped', 0, 'Previous run still active (lock held).');
            return ['status' => 'skipped', 'processed' => 0, 'message' => 'Previous run still active.'];
        }
        $id = $this->start($script, $trigger);
        try {
            [$processed, $message] = $fn();
            $this->finish($id, 'completed', (int)$processed, $message);
            return ['status' => 'completed', 'processed' => (int)$processed, 'message' => $message];
        } catch (\Throwable $e) {
            \BharatSeo\Core\ErrorHandler::report($e);
            $this->finish($id, 'failed', 0, get_class($e) . ': ' . \BharatSeo\Core\Logger::redactString($e->getMessage()));
            return ['status' => 'failed', 'processed' => 0, 'message' => $e->getMessage()];
        } finally {
            $this->unlock();
        }
    }

    /** @return array<string,array{label:string,last_started:?string,last_finished:?string,last_status:?string,last_message:?string,healthy:bool,expected_minutes:int}> */
    public function health(): array
    {
        $base = max(1, (int)$this->app->settings()->get('cron.expected_interval_minutes', 5));
        $out = [];
        foreach (self::SCRIPTS as $script => $meta) {
            $last = $this->db->fetch('SELECT * FROM cron_runs WHERE script = ? ORDER BY id DESC LIMIT 1', [$script]);
            $lastOk = $this->db->fetchColumn("SELECT MAX(finished_at) FROM cron_runs WHERE script = ? AND status IN ('completed','skipped')", [$script]);
            $expected = $base * $meta['every'];
            $healthy = $lastOk !== null && (time() - strtotime($lastOk . ' UTC')) <= ($expected * 3 + 5) * 60;
            $out[$script] = [
                'label' => $meta['label'],
                'last_started' => $last['started_at'] ?? null,
                'last_finished' => $lastOk,
                'last_status' => $last['status'] ?? null,
                'last_message' => $last['message'] ?? null,
                'last_trigger' => $last['trigger_type'] ?? null,
                'healthy' => $healthy,
                'expected_minutes' => $expected,
            ];
        }
        return $out;
    }

    public function isAutomationActive(): bool
    {
        $h = $this->health();
        return $h['process_queue']['healthy'];
    }

    /** Human-readable system warnings for dashboards. */
    public function warnings(): array
    {
        $w = [];
        $s = $this->app->settings();
        if ($s->bool('queue.paused')) {
            $w[] = ['level' => 'danger', 'text' => 'All queued jobs are PAUSED by an administrator. No discovery, monitoring or sending is running.'];
        }
        $h = $this->health();
        if (!$h['process_queue']['healthy']) {
            $w[] = ['level' => 'danger', 'text' => $h['process_queue']['last_finished'] === null
                ? 'Cron is not configured: the job queue has never run. Automation is NOT active — see CRON_SETUP.md.'
                : 'Cron appears to be stopped: the job queue last ran ' . fmt_dt($h['process_queue']['last_finished']) . '. Automation is NOT active.'];
        } else {
            foreach ($h as $script => $info) {
                if (!$info['healthy'] && $script !== 'cleanup') {
                    $w[] = ['level' => 'warning', 'text' => $info['label'] . ' cron has not run recently (last: ' . fmt_dt($info['last_finished']) . ').'];
                }
            }
        }
        if (!$this->app->make(MailerService::class)->isConfigured()) {
            $w[] = ['level' => 'warning', 'text' => 'SMTP is not configured. Approved outreach will not be sent; use Copy / Export to send manually.'];
        }
        $failed = (int)$this->db->fetchColumn('SELECT COUNT(*) FROM failed_jobs WHERE resolved_at IS NULL');
        if ($failed > 0) {
            $w[] = ['level' => 'warning', 'text' => "$failed failed job(s) need review."];
        }
        $stuck = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM outreach_messages WHERE status = 'failed'");
        if ($stuck > 0) {
            $w[] = ['level' => 'warning', 'text' => "$stuck outreach message(s) failed to send."];
        }
        return $w;
    }
}
