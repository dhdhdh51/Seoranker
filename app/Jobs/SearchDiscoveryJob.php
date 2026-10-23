<?php
declare(strict_types=1);

namespace BharatSeo\Jobs;

use BharatSeo\Core\App;
use BharatSeo\Services\DiscoveryService;
use BharatSeo\Services\SearchProviderService;

/**
 * Runs queued search-provider queries one at a time; progress is stored in the
 * payload-independent discovery run so retries do not repeat finished queries.
 */
final class SearchDiscoveryJob implements JobHandler
{
    public function __construct(private App $app)
    {
    }

    public function handle(array $job): void
    {
        $runId = (int)($job['data']['run_id'] ?? 0);
        $db = $this->app->db();
        $run = $db->fetch('SELECT * FROM discovery_runs WHERE id = ?', [$runId]);
        if (!$run || in_array($run['status'], ['completed', 'failed'], true)) {
            return;
        }
        $project = $db->fetch("SELECT * FROM projects WHERE id = ? AND status = 'active'", [$run['project_id']]);
        if (!$project) {
            throw new PermanentException('Project inactive.');
        }
        $queries = (array)($job['data']['queries'] ?? []);
        $category = (string)($job['data']['category'] ?? 'resource_page');
        $settings = $this->app->settings();
        $doneKey = 'run_' . $runId . '_queries_done';
        $done = (int)$settings->project((int)$project['id'], $doneKey, '0');
        $search = $this->app->make(SearchProviderService::class);
        $discovery = $this->app->make(DiscoveryService::class);
        $deadline = microtime(true) + 40;
        for ($i = $done; $i < count($queries) && microtime(true) < $deadline; $i++) {
            $results = $search->search((string)$queries[$i]); // may throw Retryable (quota) → resumes at $i later
            $rows = [];
            foreach ($results as $r) {
                $rows[] = [
                    'url' => $r['url'],
                    'title' => mb_substr($r['title'], 0, 500),
                    'category' => $category,
                    'evidence' => mb_substr('Found via ' . $search->providerName() . ' for query: ' . $queries[$i] . ($r['snippet'] !== '' ? ' — snippet: ' . $r['snippet'] : ''), 0, 1000),
                ];
            }
            $res = $discovery->ingest($project, $rows, ['source' => 'search_api'], $runId, $run['started_by'] ? (int)$run['started_by'] : null);
            $discovery->incrementRun($runId, $res);
            $settings->setProject((int)$project['id'], $doneKey, (string)($i + 1));
        }
        if ($i < count($queries)) {
            throw new RetryableException('Search discovery continues in the next run.', 30);
        }
        $discovery->finishRun($runId, []);
    }
}
