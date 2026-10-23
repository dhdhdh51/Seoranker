<?php
declare(strict_types=1);

/**
 * Shared cron runner. Cron scripts must be executed with PHP CLI
 * (or through the authenticated public/cron.php fallback).
 */

use BharatSeo\Core\App;
use BharatSeo\Core\NotInstalledException;
use BharatSeo\Services\BacklinkService;
use BharatSeo\Services\CronService;
use BharatSeo\Services\OutreachService;
use BharatSeo\Services\OutreachSender;
use BharatSeo\Services\QueueService;
use BharatSeo\Services\SearchProviderService;

if (PHP_SAPI !== 'cli' && !defined('BHARATSEO_WEB_CRON')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

/** Task implementations. Each returns [processed, message]. Budgets keep every run bounded. */
final class BharatSeoCronTasks
{
    public static function workerId(): string
    {
        return substr((gethostname() ?: 'host') . ':' . getmypid() . ':' . bin2hex(random_bytes(3)), 0, 100);
    }

    public static function processQueue(App $app, int $budget): array
    {
        $s = $app->settings();
        $stats = $app->make(QueueService::class)->work(self::workerId(), min($budget, (int)$s->get('queue.max_runtime_seconds', 50)), (int)$s->get('queue.max_jobs_per_run', 40));
        return [$stats['processed'], $stats['paused'] ? 'Queue paused by administrator' : sprintf('processed=%d retried=%d failed=%d', $stats['processed'], $stats['retried'], $stats['failed'])];
    }

    public static function sendOutreach(App $app, int $budget): array
    {
        if ($app->settings()->bool('queue.paused')) {
            return [0, 'Queue paused by administrator'];
        }
        $followUps = $app->make(OutreachService::class)->generateDueFollowUps(10);
        $r = $app->make(OutreachSender::class)->run(20, max(10, $budget - 5));
        return [$r['sent'], sprintf('status=%s sent=%d failed=%d deferred=%d suppressed=%d skipped=%d follow_up_drafts=%d', $r['status'], $r['sent'], $r['failed'], $r['deferred'], $r['suppressed'], $r['skipped'], $followUps)];
    }

    public static function checkBacklinks(App $app, int $budget): array
    {
        if (!$app->settings()->moduleEnabled('monitoring')) {
            return [0, 'Monitoring module disabled'];
        }
        if ($app->settings()->bool('queue.paused')) {
            return [0, 'Queue paused by administrator'];
        }
        $queued = $app->make(BacklinkService::class)->enqueueDue((int)$app->settings()->get('monitor.max_checks_per_run', 25));
        $stats = $app->make(QueueService::class)->work(self::workerId(), max(10, $budget - 5), 25, ['check_backlink']);
        return [$stats['processed'], sprintf('due_enqueued=%d checked=%d retried=%d failed=%d', $queued, $stats['processed'], $stats['retried'], $stats['failed'])];
    }

    public static function discover(App $app, int $budget): array
    {
        if (!$app->settings()->moduleEnabled('discovery')) {
            return [0, 'Discovery module disabled'];
        }
        if ($app->settings()->bool('queue.paused')) {
            return [0, 'Queue paused by administrator'];
        }
        $db = $app->db();
        $q = $app->make(QueueService::class);
        // 1. Make sure every un-evaluated prospect has an evaluation job (idempotent unique keys).
        $rows = $db->fetchAll("SELECT pp.id, pp.project_id FROM prospect_pages pp JOIN projects p ON p.id = pp.project_id AND p.status = 'active'
                               WHERE pp.status = 'discovered' AND pp.last_fetched_at IS NULL ORDER BY pp.id LIMIT 300");
        $n = 0;
        foreach ($rows as $r) {
            $q->push('evaluate_prospect', ['prospect_id' => (int)$r['id']], (int)$r['project_id'], 'evaluate:' . $r['id'], 0, 6);
            $n++;
        }
        // 2. Refresh stale assessments of prospects still under consideration (90 days).
        $stale = $db->fetchAll("SELECT pp.id, pp.project_id FROM prospect_pages pp JOIN projects p ON p.id = pp.project_id AND p.status = 'active'
                                WHERE pp.status IN ('evaluated','needs_review') AND pp.last_fetched_at < UTC_TIMESTAMP() - INTERVAL 90 DAY ORDER BY pp.last_fetched_at LIMIT 20");
        foreach ($stale as $r) {
            $q->push('evaluate_prospect', ['prospect_id' => (int)$r['id']], (int)$r['project_id'], 'evaluate:' . $r['id'], 0, 8);
        }
        // 3. Daily scheduled search discovery for opted-in projects (only when a provider is configured).
        $searches = 0;
        $search = $app->make(SearchProviderService::class);
        if ($search->isAvailable()) {
            $projects = $db->fetchAll("SELECT p.* FROM projects p JOIN project_settings s ON s.project_id = p.id AND s.setting_key = 'auto_search' AND s.value = '1' WHERE p.status = 'active'");
            $keywordsOf = static fn(int $pid): array => array_column($db->fetchAll("SELECT keyword FROM project_keywords WHERE project_id = ? AND type = 'keyword' LIMIT 4", [$pid]), 'keyword');
            foreach ($projects as $p) {
                $key = 'autosearch:' . $p['id'] . ':' . gmdate('Y-m-d');
                if ($db->fetchColumn('SELECT 1 FROM discovery_runs WHERE project_id = ? AND source_type = \'search_api\' AND source_detail LIKE ? AND created_at >= UTC_DATE()', [$p['id'], 'Scheduled%'])) {
                    continue;
                }
                $queries = array_slice(SearchProviderService::footprints($p, $keywordsOf((int)$p['id']), 'resource_page'), 0, 3);
                if (!$queries) {
                    continue;
                }
                $runId = $db->insert('discovery_runs', ['project_id' => (int)$p['id'], 'client_id' => (int)$p['client_id'], 'source_type' => 'search_api', 'source_detail' => mb_substr('Scheduled: ' . implode(' | ', $queries), 0, 500), 'default_category' => 'resource_page', 'status' => 'queued']);
                $q->push('search_discovery', ['run_id' => $runId, 'queries' => $queries, 'category' => 'resource_page'], (int)$p['id'], $key, 0, 7);
                $searches++;
            }
        }
        // 4. Process a bounded batch of discovery-related jobs right away.
        $stats = $q->work(self::workerId(), max(10, $budget - 10), 20, ['evaluate_prospect', 'process_import', 'search_discovery', 'extract_public_page']);
        return [$stats['processed'], sprintf('evaluations_ensured=%d stale_refresh=%d scheduled_searches=%d processed=%d', $n, count($stale), $searches, $stats['processed'])];
    }

    public static function cleanup(App $app, int $budget): array
    {
        $db = $app->db();
        $n = 0;
        $n += $db->run("DELETE FROM job_queue WHERE status IN ('completed','cancelled') AND updated_at < UTC_TIMESTAMP() - INTERVAL 14 DAY LIMIT 5000")->rowCount();
        $n += $db->run("DELETE j FROM job_queue j LEFT JOIN failed_jobs f ON f.job_id = j.id AND f.resolved_at IS NULL WHERE j.status = 'failed' AND j.updated_at < UTC_TIMESTAMP() - INTERVAL 60 DAY AND f.id IS NULL")->rowCount();
        $n += $db->run('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 30 DAY')->rowCount();
        $n += $db->run('DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY')->rowCount();
        $n += $db->run('DELETE FROM rate_limits WHERE last_hit_at < UTC_TIMESTAMP() - INTERVAL 2 DAY')->rowCount();
        $n += $db->run('DELETE FROM robots_cache WHERE fetched_at < UTC_TIMESTAMP() - INTERVAL 7 DAY')->rowCount();
        $n += $db->run('DELETE FROM notifications WHERE is_read = 1 AND created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY')->rowCount();
        $n += $db->run('DELETE FROM cron_runs WHERE started_at < UTC_TIMESTAMP() - INTERVAL 30 DAY')->rowCount();
        $n += $db->run("UPDATE cron_runs SET status = 'failed', message = 'Run did not finish (process killed or timed out)', finished_at = UTC_TIMESTAMP() WHERE status = 'running' AND started_at < UTC_TIMESTAMP() - INTERVAL 2 HOUR")->rowCount();
        // Uploaded CSVs of finished imports (rows already imported) after 30 days.
        foreach ($db->fetchAll("SELECT id, stored_path FROM import_jobs WHERE status IN ('completed','failed') AND finished_at < UTC_TIMESTAMP() - INTERVAL 30 DAY AND stored_path <> ''") as $imp) {
            @unlink($app->config->storagePath('uploads/imports/' . basename($imp['stored_path'])));
            $db->update('import_jobs', ['stored_path' => ''], 'id = ?', [$imp['id']]);
            $n++;
        }
        // Expired session files, old logs and dev mail files.
        $sweep = static function (string $dir, int $maxAgeSeconds, string $pattern) use (&$n): void {
            foreach (glob(rtrim($dir, '/') . '/' . $pattern) ?: [] as $f) {
                if (is_file($f) && filemtime($f) < time() - $maxAgeSeconds) {
                    @unlink($f);
                    $n++;
                }
            }
        };
        $sweep($app->config->storagePath('sessions'), 86400, 'sess_*');
        $sweep($app->config->storagePath('logs'), 30 * 86400, '*.log');
        $sweep($app->config->storagePath('mail'), 14 * 86400, '*.eml');
        // Audit logs are never deleted.
        return [$n, "removed_or_updated=$n (audit logs are retained)"];
    }

    /** @return array<string,array{0:callable,1:int}> task name => [callable, budget seconds] */
    public static function registry(): array
    {
        return [
            'process_queue' => [[self::class, 'processQueue'], 55],
            'send_approved_outreach' => [[self::class, 'sendOutreach'], 50],
            'check_backlinks' => [[self::class, 'checkBacklinks'], 55],
            'discover' => [[self::class, 'discover'], 55],
            'cleanup' => [[self::class, 'cleanup'], 50],
        ];
    }
}

/** Run a task with lock + heartbeat. Returns a process exit code. */
function bharatseo_run_cron(string $task, string $trigger = 'cli', ?int $budget = null): array
{
    try {
        $app = App::hasInstance() ? App::get() : App::boot();
    } catch (NotInstalledException) {
        return ['status' => 'failed', 'processed' => 0, 'message' => 'Not installed'];
    }
    $reg = BharatSeoCronTasks::registry();
    if (!isset($reg[$task])) {
        return ['status' => 'failed', 'processed' => 0, 'message' => 'Unknown task'];
    }
    [$fn, $defaultBudget] = $reg[$task];
    $budget ??= $defaultBudget;
    @set_time_limit($budget + 60);
    ignore_user_abort(true);
    return $app->make(CronService::class)->run($task, $trigger, static fn() => $fn($app, $budget));
}

function bharatseo_cli_cron(string $task): void
{
    $r = bharatseo_run_cron($task, 'cli');
    fwrite(STDOUT, sprintf("[%s] %s: %s (%d) %s\n", gmdate('c'), $task, $r['status'], $r['processed'], (string)$r['message']));
    exit($r['status'] === 'failed' ? 1 : 0);
}
