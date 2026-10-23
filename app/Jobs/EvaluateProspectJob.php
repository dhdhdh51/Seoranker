<?php
declare(strict_types=1);

namespace BharatSeo\Jobs;

use BharatSeo\Core\App;
use BharatSeo\Services\EvaluationService;

/** Fetch + score a prospect. Idempotent: re-running replaces the "current" assessment. */
final class EvaluateProspectJob implements JobHandler
{
    public function __construct(private App $app)
    {
    }

    public function handle(array $job): void
    {
        $id = (int)($job['data']['prospect_id'] ?? 0);
        if ($id <= 0) {
            throw new PermanentException('Missing prospect_id');
        }
        $final = (int)$job['attempts'] >= (int)$job['max_attempts'];
        $userId = isset($job['data']['user_id']) ? (int)$job['data']['user_id'] : null;
        $this->app->make(EvaluationService::class)->evaluate($id, $userId, $final);
    }
}
