<?php
declare(strict_types=1);

namespace BharatSeo\Jobs;

use BharatSeo\Core\App;
use BharatSeo\Services\DiscoveryService;
use BharatSeo\Services\QueueService;

/**
 * Processes an import in bounded chunks. When more rows remain it enqueues a
 * continuation job (a new unique key per byte offset keeps it idempotent).
 */
final class ProcessImportJob implements JobHandler
{
    public function __construct(private App $app)
    {
    }

    public function handle(array $job): void
    {
        $id = (int)($job['data']['import_id'] ?? 0);
        if ($id <= 0) {
            throw new PermanentException('Missing import_id');
        }
        $done = $this->app->make(DiscoveryService::class)->processImportChunk($id);
        if (!$done) {
            $offset = (int)$this->app->db()->fetchColumn('SELECT byte_offset FROM import_jobs WHERE id = ?', [$id]);
            $this->app->make(QueueService::class)->push('process_import', ['import_id' => $id], $job['project_id'] !== null ? (int)$job['project_id'] : null, 'import:' . $id . ':' . $offset, 0, 4);
        }
    }
}
