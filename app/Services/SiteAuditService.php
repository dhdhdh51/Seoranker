<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Core\HttpException;
use BharatSeo\Core\ValidationException;
use BharatSeo\Crawler\HttpClient;
use BharatSeo\Crawler\HttpResponse;
use BharatSeo\Crawler\PageFetcher;
use BharatSeo\Crawler\Url;
use BharatSeo\Security\Gate;
use BharatSeo\Security\User;
use BharatSeo\Services\Audit\PageAnalyzer;

/**
 * Technical / on-page SEO audit of a project's own website.
 * Requires verified ownership. Crawls in bounded chunks through the job queue,
 * respects robots.txt, and stores every page and issue in the database.
 */
final class SiteAuditService
{
    public const CHUNK_SECONDS = 40;

    private Database $db;
    private ?PageFetcher $fetcher = null;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    public function setFetcher(?PageFetcher $f): void
    {
        $this->fetcher = $f;
    }

    private function fetcher(): PageFetcher
    {
        return $this->fetcher ?? $this->app->make(HttpClient::class);
    }

    public function start(User $user, int $projectId, array $input): int
    {
        if (!$this->app->settings()->moduleEnabled('audit')) {
            throw new HttpException(403, 'The site audit module is disabled by the administrator.');
        }
        $project = $this->app->make(ProjectService::class)->find($user, $projectId, Gate::MANAGE);
        if (!ProjectService::isVerified($project)) {
            throw ValidationException::single('project_id', 'Verify ownership of ' . $project['domain'] . ' on the project page before auditing the site.');
        }
        $start = trim((string)($input['start_url'] ?? '')) !== '' ? Url::normalize((string)$input['start_url']) : Url::normalize($project['website_url']);
        if ($start === null || !Url::belongsToDomain($start, $project['domain'])) {
            throw ValidationException::single('start_url', 'The start URL must be on ' . $project['domain'] . '.');
        }
        $maxPages = max(1, min(500, (int)($input['max_pages'] ?? 100) ?: 100));
        $maxDepth = max(0, min(10, (int)($input['max_depth'] ?? 4)));
        if ($this->db->fetchColumn("SELECT 1 FROM site_audits WHERE project_id = ? AND status IN ('queued','running')", [$projectId])) {
            throw ValidationException::single('project_id', 'An audit is already running for this project.');
        }
        $id = $this->db->insert('site_audits', [
            'project_id' => $projectId,
            'client_id' => (int)$project['client_id'],
            'start_url' => $start,
            'host' => (string)Url::host($start),
            'max_pages' => $maxPages,
            'max_depth' => $maxDepth,
            'created_by' => $user->id,
        ]);
        $this->app->make(QueueService::class)->push('site_audit', ['audit_id' => $id], $projectId, 'audit:' . $id . ':0', 0, 3, 8);
        $this->app->make(AuditLogger::class)->log('site_audit.started', $user->id, $projectId, ['audit_id' => $id, 'start_url' => $start, 'max_pages' => $maxPages], 'site_audit', null, $id);
        return $id;
    }

    public function find(User $user, int $id): array
    {
        [$scope, $params] = $this->app->make(Gate::class)->scope($user, 'a.project_id');
        $a = $this->db->fetch("SELECT a.*, p.name AS project_name, p.domain AS project_domain FROM site_audits a JOIN projects p ON p.id = a.project_id WHERE a.id = ?$scope", array_merge([$id], $params));
        if (!$a) {
            throw new HttpException(404);
        }
        return $a;
    }

    public function list(User $user, ?int $projectId): array
    {
        [$scope, $params] = $this->app->make(Gate::class)->scope($user, 'a.project_id');
        $sql = "SELECT a.*, p.name AS project_name FROM site_audits a JOIN projects p ON p.id = a.project_id WHERE 1=1$scope";
        if ($projectId) {
            $sql .= ' AND a.project_id = ?';
            $params[] = $projectId;
        }
        return $this->db->fetchAll($sql . ' ORDER BY a.id DESC LIMIT 50', $params);
    }

    public function pages(int $auditId): array
    {
        return $this->db->fetchAll("SELECT * FROM site_audit_pages WHERE audit_id = ? AND status = 'done' ORDER BY depth, id", [$auditId]);
    }

    public function cancel(User $user, int $id): void
    {
        $a = $this->find($user, $id);
        $this->app->make(Gate::class)->authorizeProject($user, (int)$a['project_id'], Gate::MANAGE);
        $this->db->run("UPDATE site_audits SET status = 'cancelled', finished_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('queued','running')", [$id]);
    }

    /** Process a bounded chunk. Returns true when the audit is finished. */
    public function processChunk(int $auditId, int $maxSeconds = self::CHUNK_SECONDS): bool
    {
        $a = $this->db->fetch('SELECT * FROM site_audits WHERE id = ?', [$auditId]);
        if (!$a || in_array($a['status'], ['completed', 'failed', 'cancelled'], true)) {
            return true;
        }
        $deadline = microtime(true) + $maxSeconds;
        $interval = (int)$this->app->settings()->get('audit.interval_ms', 1000);
        if ($a['status'] === 'queued') {
            $this->db->update('site_audits', ['status' => 'running', 'started_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$auditId]);
            $checks = $this->siteChecks($a, $interval);
            $this->db->update('site_audits', ['site_checks' => json_encode($checks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)], 'id = ?', [$auditId]);
            $this->enqueue($auditId, $a['start_url'], 0, null, false);
            foreach (array_slice($checks['sitemap_urls'] ?? [], 0, (int)$a['max_pages']) as $u) {
                if (PageAnalyzer::sameSite((string)Url::host($u), $a['host'])) {
                    $this->enqueue($auditId, $u, 1, 'sitemap.xml', true);
                }
            }
            $this->db->run('UPDATE site_audit_pages SET in_sitemap = 1 WHERE audit_id = ? AND url_hash IN (' . Database::in(array_map([Url::class, 'dedupeKey'], $checks['sitemap_urls'] ?: ['-'])) . ')', array_merge([$auditId], array_map([Url::class, 'dedupeKey'], $checks['sitemap_urls'] ?: ['-'])));
        }
        while (microtime(true) < $deadline) {
            $status = $this->db->fetchColumn('SELECT status FROM site_audits WHERE id = ?', [$auditId]);
            if ($status !== 'running') {
                return true;
            }
            $crawled = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM site_audit_pages WHERE audit_id = ? AND status = 'done'", [$auditId]);
            if ($crawled >= (int)$a['max_pages']) {
                break;
            }
            $page = $this->db->fetch("SELECT * FROM site_audit_pages WHERE audit_id = ? AND status = 'pending' ORDER BY depth, id LIMIT 1", [$auditId]);
            if (!$page) {
                break;
            }
            $resp = $this->fetcher()->fetch($page['url'], ['interval_ms' => $interval]);
            if ($resp->errorType === HttpResponse::ERR_RATE_LIMITED) {
                usleep(300000);
                continue;
            }
            $this->storePage($a, $page, $resp);
        }
        $crawled = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM site_audit_pages WHERE audit_id = ? AND status = 'done'", [$auditId]);
        $pending = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM site_audit_pages WHERE audit_id = ? AND status = 'pending'", [$auditId]);
        $found = $crawled + $pending;
        $this->db->update('site_audits', ['pages_crawled' => $crawled, 'pages_found' => $found], 'id = ?', [$auditId]);
        if ($pending === 0 || $crawled >= (int)$a['max_pages']) {
            $this->finalize($auditId);
            return true;
        }
        return false;
    }

    private function enqueue(int $auditId, string $url, int $depth, ?string $foundOn, bool $inSitemap): void
    {
        $n = Url::normalize($url);
        if ($n === null) {
            return;
        }
        $this->db->run(
            'INSERT IGNORE INTO site_audit_pages (audit_id, url, url_hash, depth, found_on, in_sitemap) VALUES (?, ?, ?, ?, ?, ?)',
            [$auditId, mb_substr($n, 0, 2048), Url::dedupeKey($n), $depth, $foundOn !== null ? mb_substr($foundOn, 0, 2048) : null, (int)$inSitemap]
        );
    }

    private function storePage(array $a, array $page, HttpResponse $resp): void
    {
        $robotsBlocked = $resp->errorType === HttpResponse::ERR_ROBOTS;
        $res = $robotsBlocked
            ? ['metrics' => ['error' => 'Blocked by robots.txt — not audited'], 'issues' => [], 'links' => []]
            : PageAnalyzer::analyze($resp, $page['url'], $a['host'], (int)$page['depth']);
        $m = $res['metrics'];
        $update = ['status' => 'done', 'fetched_at' => gmdate('Y-m-d H:i:s'), 'issues' => json_encode($res['issues']), 'outlinks' => json_encode($res['links'], JSON_UNESCAPED_SLASHES)];
        foreach (['http_status', 'final_url', 'redirect_count', 'content_type', 'is_html', 'response_ms', 'size_bytes', 'title', 'meta_description', 'h1', 'h1_count', 'word_count', 'canonical', 'robots_meta', 'internal_links', 'external_links', 'images', 'images_missing_alt', 'error'] as $k) {
            if (array_key_exists($k, $m)) {
                $v = $m[$k];
                $update[$k] = is_string($v) ? mb_substr($v, 0, $k === 'meta_description' ? 1000 : ($k === 'error' ? 500 : 2048)) : $v;
            }
        }
        $this->db->update('site_audit_pages', $update, 'id = ?', [$page['id']]);
        if ((int)$page['depth'] < (int)$a['max_depth']) {
            $total = (int)$this->db->fetchColumn('SELECT COUNT(*) FROM site_audit_pages WHERE audit_id = ?', [$a['id']]);
            foreach ($res['links'] as $link) {
                if ($total >= (int)$a['max_pages'] * 2) {
                    break;
                }
                $this->enqueue((int)$a['id'], $link, (int)$page['depth'] + 1, $page['url'], false);
                $total++;
            }
        }
    }

    /** Site-wide checks: HTTPS, http→https redirect, host consolidation, robots.txt, sitemap.xml, homepage speed. */
    public function siteChecks(array $a, int $interval): array
    {
        $f = $this->fetcher();
        $host = $a['host'];
        $origin = 'https://' . $host;
        $issues = [];
        $info = [];
        $home = $f->fetch($origin . '/', ['interval_ms' => $interval]);
        $info['https_ok'] = $home->errorType === null && $home->status > 0 && $home->status < 400;
        $info['homepage_ms'] = $home->timeMs;
        $info['homepage_status'] = $home->status;
        if (!$info['https_ok']) {
            $issues[] = 'no_https';
            $info['https_error'] = $home->error ?? ('HTTP ' . $home->status);
        } elseif ($home->timeMs > 1500) {
            $issues[] = 'slow_homepage';
        }
        $http = $f->fetch('http://' . $host . '/', ['interval_ms' => $interval]);
        $info['http_redirects_to'] = $http->finalUrl;
        if ($info['https_ok'] && $http->errorType === null && !str_starts_with($http->finalUrl, 'https://')) {
            $issues[] = 'no_https_redirect';
        }
        $alt = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
        $altResp = $f->fetch('https://' . $alt . '/', ['interval_ms' => $interval]);
        $info['alternate_host'] = $alt;
        if ($altResp->errorType === null && $altResp->status === 200 && Url::host($altResp->finalUrl) === $alt) {
            $issues[] = 'host_not_consolidated';
        }
        $robots = $f->fetch($origin . '/robots.txt', ['respect_robots' => false, 'accept' => ['text/plain', ''], 'interval_ms' => $interval]);
        $robotsBody = $robots->ok() ? $robots->body : '';
        $info['robots_status'] = $robots->status;
        if (!$robots->ok()) {
            $issues[] = 'no_robots';
        } elseif (!(new \BharatSeo\Crawler\RobotsTxt($robotsBody))->isAllowed($origin . '/', 'Googlebot')) {
            $issues[] = 'robots_blocks_all';
        }
        $sitemaps = [];
        if (preg_match_all('/^\s*Sitemap:\s*(\S+)/mi', $robotsBody, $mm)) {
            $sitemaps = $mm[1];
        }
        $inRobots = $sitemaps !== [];
        $sitemaps[] = $origin . '/sitemap.xml';
        $urls = [];
        $seen = [];
        $queue = array_values(array_unique($sitemaps));
        while ($queue && count($seen) < 10) {
            $sm = array_shift($queue);
            if (isset($seen[$sm])) {
                continue;
            }
            $seen[$sm] = true;
            $r = $f->fetch($sm, ['accept' => ['application/xml', 'text/xml', 'application/x-xml', 'text/plain', ''], 'interval_ms' => $interval]);
            if (!$r->ok() || !str_contains($r->body, '<loc')) {
                continue;
            }
            preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $r->body, $locs);
            foreach ($locs[1] as $loc) {
                $loc = html_entity_decode($loc);
                if (preg_match('/\.xml(\.gz)?(\?|$)/i', $loc) && str_contains($r->body, '<sitemapindex')) {
                    $queue[] = $loc;
                } elseif (($n = Url::normalize($loc)) !== null) {
                    $urls[$n] = true;
                }
            }
        }
        $info['sitemap_found'] = $urls !== [];
        $info['sitemap_url_count'] = count($urls);
        if ($urls === []) {
            $issues[] = 'no_sitemap';
        } elseif (!$inRobots && $robots->ok()) {
            $issues[] = 'sitemap_not_in_robots';
        }
        return ['issues' => $issues, 'info' => $info, 'sitemap_urls' => array_slice(array_keys($urls), 0, 1000)];
    }

    /** Cross-page checks (duplicates, broken internal links, redirects, sitemap coverage) and the final score. */
    public function finalize(int $auditId): void
    {
        $a = $this->db->fetch('SELECT * FROM site_audits WHERE id = ?', [$auditId]);
        $pages = $this->pages($auditId);
        $checks = json_decode((string)$a['site_checks'], true) ?: ['issues' => [], 'info' => []];
        $byKey = [];
        foreach ($pages as $i => $p) {
            $pages[$i]['_issues'] = json_decode((string)$p['issues'], true) ?: [];
            $byKey[Url::dedupeKey($p['url'])] = $i;
        }
        $indexable = static fn(array $p): bool => (int)$p['is_html'] === 1 && (int)$p['http_status'] === 200 && !str_contains((string)$p['robots_meta'], 'noindex');
        // Duplicates
        foreach (['title' => 'duplicate_title', 'meta_description' => 'duplicate_meta_description'] as $field => $code) {
            $groups = [];
            foreach ($pages as $i => $p) {
                if ($indexable($p) && ($v = mb_strtolower(trim((string)$p[$field]))) !== '') {
                    $groups[$v][] = $i;
                }
            }
            foreach ($groups as $idx) {
                if (count($idx) > 1) {
                    foreach ($idx as $i) {
                        $pages[$i]['_issues'][] = $code;
                    }
                }
            }
        }
        // Broken internal links and links to redirects
        foreach ($pages as $i => $p) {
            foreach (json_decode((string)$p['outlinks'], true) ?: [] as $link) {
                $t = $byKey[Url::dedupeKey($link)] ?? null;
                if ($t === null) {
                    continue;
                }
                $target = $pages[$t];
                if (in_array('fetch_failed', $target['_issues'], true) || (int)$target['http_status'] >= 400) {
                    $pages[$i]['_issues'][] = 'broken_internal_link';
                } elseif ((int)$target['redirect_count'] > 0) {
                    $pages[$i]['_issues'][] = 'internal_redirect';
                }
            }
        }
        // Sitemap coverage
        if (!empty($checks['info']['sitemap_found'])) {
            foreach ($pages as $i => $p) {
                if ($indexable($p) && (int)$p['in_sitemap'] === 0 && Url::sameResource((string)($p['canonical'] ?? $p['url']), $p['url'])) {
                    $pages[$i]['_issues'][] = 'not_in_sitemap';
                }
            }
        }
        $counts = [];
        $sev = ['error' => 0, 'warning' => 0, 'notice' => 0];
        foreach ($pages as $p) {
            $issues = array_values(array_unique($p['_issues']));
            $this->db->update('site_audit_pages', ['issues' => json_encode($issues)], 'id = ?', [$p['id']]);
            foreach ($issues as $code) {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
                $sev[PageAnalyzer::ISSUES[$code][0] ?? 'notice']++;
            }
        }
        foreach ($checks['issues'] as $code) {
            $sev[PageAnalyzer::SITE_ISSUES[$code][0] ?? 'notice']++;
        }
        $htmlPages = count(array_filter($pages, static fn($p) => (int)$p['is_html'] === 1));
        $score = PageAnalyzer::score(array_diff_key($counts, ['non_html' => 1]), $checks['issues'], max(1, $htmlPages));
        arsort($counts);
        $this->db->update('site_audits', [
            'status' => 'completed',
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'pages_crawled' => count($pages),
            'score' => $score,
            'error_count' => $sev['error'],
            'warning_count' => $sev['warning'],
            'notice_count' => $sev['notice'],
            'summary' => json_encode(['issue_counts' => $counts, 'html_pages' => $htmlPages, 'avg_response_ms' => (int)round(array_sum(array_column($pages, 'response_ms')) / max(1, count($pages)))]),
        ], 'id = ?', [$auditId]);
        $this->app->make(AuditLogger::class)->log('site_audit.completed', null, (int)$a['project_id'], ['audit_id' => $auditId, 'score' => $score, 'pages' => count($pages)], 'site_audit', null, $auditId);
        $this->app->make(NotificationService::class)->notifyProject((int)$a['project_id'], 'site_audit', 'Site audit finished: score ' . $score . '/100', count($pages) . ' pages audited', url('/audits/' . $auditId), true);
        if ($a['created_by']) {
            $this->app->make(NotificationService::class)->notify((int)$a['created_by'], 'site_audit', 'Site audit finished: score ' . $score . '/100', count($pages) . ' pages audited', url('/audits/' . $auditId), (int)$a['project_id']);
        }
    }

    public function fail(int $auditId, string $error): void
    {
        $this->db->run("UPDATE site_audits SET status = 'failed', error_message = ?, finished_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('queued','running')", [mb_substr($error, 0, 1000), $auditId]);
    }

    /** Issues grouped by code with the affected pages, ordered by severity. */
    public function issueGroups(array $audit, array $pages): array
    {
        $groups = [];
        foreach ((json_decode((string)$audit['site_checks'], true)['issues'] ?? []) as $code) {
            [$sev, $title, $fix] = PageAnalyzer::SITE_ISSUES[$code];
            $groups[] = ['code' => $code, 'severity' => $sev, 'title' => $title, 'fix' => $fix, 'pages' => [], 'site' => true];
        }
        $byCode = [];
        foreach ($pages as $p) {
            foreach (json_decode((string)$p['issues'], true) ?: [] as $code) {
                $byCode[$code][] = $p;
            }
        }
        foreach ($byCode as $code => $ps) {
            [$sev, $title, $fix] = PageAnalyzer::ISSUES[$code] ?? ['notice', $code, ''];
            $groups[] = ['code' => $code, 'severity' => $sev, 'title' => $title, 'fix' => $fix, 'pages' => $ps, 'site' => false];
        }
        $order = ['error' => 0, 'warning' => 1, 'notice' => 2];
        usort($groups, static fn($x, $y) => [$order[$x['severity']], -count($x['pages'])] <=> [$order[$y['severity']], -count($y['pages'])]);
        return $groups;
    }
}
