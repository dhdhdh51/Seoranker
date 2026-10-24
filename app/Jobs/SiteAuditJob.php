<?php
declare(strict_types=1);

namespace BharatSeo\Jobs;

use BharatSeo\Core\App;
use BharatSeo\Services\QueueService;
use BharatSeo\Services\SiteAuditService;

/** Crawls one bounded chunk of a site audit and re-queues itself until finished. */
final class SiteAuditJob implements JobHandler
{
    public function __construct(private App $app)
    {
    }

    public function handle(array $job): void
    {
        $id = (int)($job['data']['audit_id'] ?? 0);
        if ($id <= 0) {
            throw new PermanentException('Missing audit_id');
        }
        $svc = $this->app->make(SiteAuditService::class);
        try {
            $done = $svc->processChunk($id);
        } catch (\Throwable $e) {
            if ((int)$job['attempts'] >= (int)$job['max_attempts']) {
                $svc->fail($id, $e->getMessage());
            }
            throw $e;
        }
        if (!$done) {
            $n = (int)$this->app->db()->fetchColumn("SELECT COUNT(*) FROM site_audit_pages WHERE audit_id = ? AND status = 'done'", [$id]);
            $this->app->make(QueueService::class)->push('site_audit', ['audit_id' => $id], $job['project_id'] !== null ? (int)$job['project_id'] : null, 'audit:' . $id . ':' . $n, 0, 3, 8);
        }
    }
}
