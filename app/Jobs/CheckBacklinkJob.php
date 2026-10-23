<?php
declare(strict_types=1);

namespace BharatSeo\Jobs;

use BharatSeo\Core\App;
use BharatSeo\Services\BacklinkMonitor;

final class CheckBacklinkJob implements JobHandler
{
    public function __construct(private App $app)
    {
    }

    public function handle(array $job): void
    {
        $id = (int)($job['data']['backlink_id'] ?? 0);
        if ($id <= 0) {
            throw new PermanentException('Missing backlink_id');
        }
        if (!$this->app->settings()->moduleEnabled('monitoring')) {
            throw new PermanentException('Monitoring module disabled.');
        }
        $this->app->make(BacklinkMonitor::class)->check($id);
    }
}
