<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Http\Response;
use BharatSeo\Services\AuditLogger;
use BharatSeo\Services\CronService;
use BharatSeo\Services\Installer;
use BharatSeo\Services\Migrator;
use BharatSeo\Services\QueueService;
use BharatSeo\Support\Paginator;

final class SystemController extends Controller
{
    public function queue(): string
    {
        $db = $this->app->db();
        $status = (string)$this->req->query('status', '');
        $where = in_array($status, ['pending', 'running', 'completed', 'failed', 'cancelled'], true) ? 'WHERE j.status = ?' : '';
        $params = $where ? [$status] : [];
        return $this->view('system/queue', [
            'title' => 'Cron & queue health',
            'stats' => $this->app->make(QueueService::class)->stats(),
            'byType' => $db->fetchAll("SELECT type, SUM(status='pending') AS pending, SUM(status='running') AS running, SUM(status='failed') AS failed, SUM(status='completed') AS completed FROM job_queue GROUP BY type ORDER BY type"),
            'jobs' => $db->fetchAll("SELECT j.*, p.name AS project_name FROM job_queue j LEFT JOIN projects p ON p.id = j.project_id $where ORDER BY j.id DESC LIMIT 50", $params),
            'failed' => $db->fetchAll('SELECT f.*, p.name AS project_name FROM failed_jobs f LEFT JOIN projects p ON p.id = f.project_id WHERE f.resolved_at IS NULL ORDER BY f.id DESC LIMIT 100'),
            'health' => $this->app->make(CronService::class)->health(),
            'warnings' => $this->app->make(CronService::class)->warnings(),
            'paused' => $this->app->settings()->bool('queue.paused'),
            'recentRuns' => $db->fetchAll('SELECT * FROM cron_runs ORDER BY id DESC LIMIT 25'),
            'phpBinary' => PHP_BINARY,
            'appRoot' => APP_ROOT,
            'webTrigger' => (bool)$this->app->config->get('cron.web_trigger_enabled', false),
            'statusFilter' => $status,
        ]);
    }

    public function pause(): Response
    {
        $this->app->settings()->set('queue.paused', true, $this->user()->id);
        $this->app->make(AuditLogger::class)->log('queue.paused', $this->user()->id);
        $this->flash('warning', 'All queued jobs are paused. Running jobs finish their current step; nothing new starts until you resume.');
        return $this->redirect('/system/queue');
    }

    public function resume(): Response
    {
        $this->app->settings()->set('queue.paused', false, $this->user()->id);
        $this->app->make(AuditLogger::class)->log('queue.resumed', $this->user()->id);
        $this->flash('success', 'Queue resumed.');
        return $this->redirect('/system/queue');
    }

    /** Bounded manual batch (max ~20 s) — useful for testing; regular processing belongs to cron. */
    public function runBatch(): Response
    {
        $stats = $this->app->make(QueueService::class)->work('web-admin-' . $this->user()->id, 20, 10);
        $this->app->make(AuditLogger::class)->log('queue.manual_batch', $this->user()->id, null, $stats);
        $this->flash($stats['paused'] ? 'warning' : 'success', $stats['paused'] ? 'Queue is paused.' : sprintf('Processed %d job(s): %d retried, %d failed.', $stats['processed'], $stats['retried'], $stats['failed']));
        return $this->redirect('/system/queue');
    }

    public function retryFailed(int $id): Response
    {
        $ok = $this->app->make(QueueService::class)->retryFailed($id, $this->user()->id);
        $this->app->make(AuditLogger::class)->log('queue.failed_retried', $this->user()->id, null, ['failed_id' => $id, 'ok' => $ok]);
        $this->flash($ok ? 'success' : 'warning', $ok ? 'Job re-queued.' : 'Job not found or already resolved.');
        return $this->redirect('/system/queue');
    }

    public function dismissFailed(int $id): Response
    {
        $ok = $this->app->make(QueueService::class)->dismissFailed($id, $this->user()->id);
        $this->app->make(AuditLogger::class)->log('queue.failed_dismissed', $this->user()->id, null, ['failed_id' => $id]);
        $this->flash($ok ? 'success' : 'warning', $ok ? 'Failed job dismissed.' : 'Job not found or already resolved.');
        return $this->redirect('/system/queue');
    }

    public function audit(): string
    {
        $db = $this->app->db();
        $where = 'WHERE 1=1';
        $params = [];
        $action = mb_substr((string)$this->req->query('action', ''), 0, 80);
        if ($action !== '') {
            $where .= ' AND a.action LIKE ?';
            $params[] = str_replace(['%', '_'], ['\%', '\_'], $action) . '%';
        }
        if ($pid = $this->req->queryInt('project_id')) {
            $where .= ' AND a.project_id = ?';
            $params[] = $pid;
        }
        if ($uid = $this->req->queryInt('user_id')) {
            $where .= ' AND a.user_id = ?';
            $params[] = $uid;
        }
        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM audit_logs a $where", $params);
        $pager = new Paginator($total, $this->page(), 50);
        $rows = $db->fetchAll("SELECT a.*, u.name AS user_name, p.name AS project_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN projects p ON p.id = a.project_id $where ORDER BY a.id DESC" . $pager->limitSql(), $params);
        return $this->view('system/audit', ['title' => 'Audit log', 'rows' => $rows, 'pager' => $pager, 'action' => $action, 'projectId' => $pid, 'projects' => $this->projectOptions(), 'verify' => $_SESSION['_audit_verify'] ?? null]);
    }

    public function verifyAudit(): Response
    {
        $r = $this->app->make(AuditLogger::class)->verifyChain();
        $_SESSION['_audit_verify'] = $r;
        $this->flash($r['ok'] ? 'success' : 'danger', $r['ok'] ? "Audit chain intact ({$r['checked']} entries verified)." : "Audit chain BROKEN at entry #{$r['broken_at']} — entries were modified or deleted outside the application.");
        return $this->redirect('/system/audit');
    }

    public function install(): string
    {
        return $this->view('system/install', [
            'title' => 'Installation & upgrade',
            'checks' => Installer::environmentChecks($this->app->config->storagePath()),
            'migrations' => $this->app->make(Migrator::class)->status(),
            'configPath' => $this->app->config->path(),
            'configOutsideWeb' => $this->app->config->path() !== null && !str_starts_with((string)realpath((string)$this->app->config->path()), (string)realpath(APP_ROOT . '/public')),
            'installerPresent' => is_file(APP_ROOT . '/public/install.php'),
            'lockPresent' => Installer::isLocked($this->app->config->storagePath()),
            'version' => APP_VERSION,
            'dbVersion' => (string)$this->app->db()->fetchColumn('SELECT VERSION()'),
        ]);
    }

    public function migrate(): Response
    {
        $done = $this->app->make(Migrator::class)->migrate($this->user()->id);
        $this->flash('success', $done ? 'Applied migrations: ' . implode(', ', $done) : 'Database is up to date.');
        return $this->redirect('/system/install');
    }
}
