<?php
declare(strict_types=1);

namespace BharatSeo\Jobs;

use BharatSeo\Core\App;
use BharatSeo\Crawler\HtmlParser;
use BharatSeo\Crawler\HttpClient;
use BharatSeo\Crawler\HttpResponse;
use BharatSeo\Services\AuditLogger;

/**
 * Checks up to 15 outbound links of a prospect page and records broken ones as
 * evidence for a broken-link replacement opportunity. Each link check is a normal
 * rate-limited, robots-respecting fetch.
 */
final class ScanBrokenLinksJob implements JobHandler
{
    public const MAX_LINKS = 15;

    public function __construct(private App $app)
    {
    }

    public function handle(array $job): void
    {
        $id = (int)($job['data']['prospect_id'] ?? 0);
        $db = $this->app->db();
        $p = $db->fetch('SELECT * FROM prospect_pages WHERE id = ?', [$id]);
        if (!$p) {
            throw new PermanentException('Prospect not found.');
        }
        $client = $this->app->make(HttpClient::class);
        $page = $client->fetch($p['normalized_url']);
        if ($page->isTemporaryFailure()) {
            throw new RetryableException('Prospect page temporarily unavailable.');
        }
        if (!$page->ok() || !$page->isHtml()) {
            throw new PermanentException('Prospect page could not be fetched: ' . ($page->error ?? 'HTTP ' . $page->status));
        }
        $parsed = HtmlParser::parse($page->body, $page->finalUrl);
        $broken = [];
        $checked = 0;
        $seen = [];
        $deadline = microtime(true) + 45;
        foreach ($parsed['links'] as $l) {
            if (!$l['external'] || !$l['in_content'] || isset($seen[$l['href']])) {
                continue;
            }
            $seen[$l['href']] = true;
            if ($checked >= self::MAX_LINKS || microtime(true) > $deadline) {
                break;
            }
            $checked++;
            $r = $client->fetch($l['href'], ['accept' => ['']]);
            // Only definitive 404/410 or DNS-dead hosts count as broken; temporary errors are ignored.
            if (($r->errorType === null && in_array($r->status, [404, 410], true)) || ($r->errorType === HttpResponse::ERR_BLOCKED && str_contains((string)$r->error, 'DNS resolution failed'))) {
                $broken[] = $l['href'] . ' (' . ($r->errorType ? 'domain no longer resolves' : 'HTTP ' . $r->status) . ($l['anchor'] !== '' ? ', anchor “' . mb_substr($l['anchor'], 0, 60) . '”' : '') . ')';
            }
        }
        $line = sprintf('Broken-link scan %s UTC: checked %d outbound link(s), %d broken%s', gmdate('Y-m-d'), $checked, count($broken), $broken ? ': ' . implode('; ', array_slice($broken, 0, 5)) : '.');
        $evidence = trim(preg_replace('/^Broken-link scan .*$/m', '', (string)$p['evidence']) . "\n" . $line);
        $db->update('prospect_pages', ['evidence' => mb_substr($evidence, 0, 5000)], 'id = ?', [$id]);
        $this->app->make(AuditLogger::class)->log('prospect.broken_link_scan', isset($job['data']['user_id']) ? (int)$job['data']['user_id'] : null, (int)$p['project_id'], ['checked' => $checked, 'broken' => count($broken)], 'prospect', null, $id);
    }
}
