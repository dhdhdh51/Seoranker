<?php
declare(strict_types=1);

namespace BharatSeo\Jobs;

use BharatSeo\Core\App;
use BharatSeo\Crawler\HtmlParser;
use BharatSeo\Crawler\HttpClient;
use BharatSeo\Crawler\HttpResponse;
use BharatSeo\Crawler\Url;
use BharatSeo\Services\DiscoveryService;
use BharatSeo\Services\ProjectService;

/**
 * Fetches a user-supplied public page (robots.txt respected) and turns its
 * in-content outbound links into candidate prospects.
 */
final class ExtractPublicPageJob implements JobHandler
{
    public function __construct(private App $app)
    {
    }

    public function handle(array $job): void
    {
        $d = $job['data'];
        $runId = (int)($d['run_id'] ?? 0);
        $db = $this->app->db();
        $run = $db->fetch('SELECT * FROM discovery_runs WHERE id = ?', [$runId]);
        if (!$run || in_array($run['status'], ['completed', 'failed'], true)) {
            return;
        }
        $project = $db->fetch("SELECT * FROM projects WHERE id = ? AND status = 'active'", [$run['project_id']]);
        if (!$project) {
            throw new PermanentException('Project inactive.');
        }
        $discovery = $this->app->make(DiscoveryService::class);
        $resp = $this->app->make(HttpClient::class)->fetch((string)$d['url']);
        if ($resp->isTemporaryFailure()) {
            throw new RetryableException('Temporary failure fetching page: ' . ($resp->error ?? 'HTTP ' . $resp->status));
        }
        if (!$resp->ok() || !$resp->isHtml()) {
            $msg = $resp->errorType === HttpResponse::ERR_ROBOTS ? 'robots.txt disallows fetching this page; it was not crawled.' : 'Could not fetch page: ' . ($resp->error ?? 'HTTP ' . $resp->status);
            $discovery->finishRun($runId, [], $msg);
            return;
        }
        $parsed = HtmlParser::parse($resp->body, $resp->finalUrl);
        $max = (int)$this->app->settings()->get('discovery.public_page_max_links', 100);
        $keywords = $this->app->make(ProjectService::class)->keywords((int)$project['id']);
        $seen = [];
        $rows = [];
        foreach ($parsed['links'] as $l) {
            if (!$l['external'] || !$l['in_content'] || $l['hidden'] || $l['sponsored']) {
                continue;
            }
            $n = Url::normalize($l['href']);
            if ($n === null || Url::belongsToDomain($n, $project['domain'])) {
                continue;
            }
            $site = Url::registrableDomain($n);
            if (isset($seen[$site]) || preg_match('#(facebook|twitter|x|instagram|linkedin|youtube|pinterest|tiktok|whatsapp|t)\.(com|me|co)$#', $site)) {
                continue;
            }
            if (!empty($d['same_topic_only']) && $keywords) {
                $hit = false;
                foreach ($keywords as $k) {
                    if (stripos($l['anchor'] . ' ' . $n, $k) !== false) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
            }
            $seen[$site] = true;
            $rows[] = [
                'url' => $n,
                'title' => $l['anchor'] !== '' ? mb_substr($l['anchor'], 0, 300) : null,
                'category' => (string)($d['category'] ?? 'resource_page'),
                'evidence' => 'Listed on public page ' . $resp->finalUrl . ($l['anchor'] !== '' ? ' with anchor “' . mb_substr($l['anchor'], 0, 100) . '”' : '') . '.',
            ];
            if (count($rows) >= $max) {
                break;
            }
        }
        $res = $discovery->ingest($project, $rows, ['source' => 'public_page'], $runId, $run['started_by'] ? (int)$run['started_by'] : null);
        $discovery->finishRun($runId, $res);
    }
}
