<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Crawler\HtmlParser;
use BharatSeo\Crawler\HttpClient;
use BharatSeo\Crawler\HttpResponse;
use BharatSeo\Crawler\PageFetcher;
use BharatSeo\Crawler\Url;
use BharatSeo\Jobs\RetryableException;
use BharatSeo\Security\RateLimiter;

/**
 * Fetches a prospect page, extracts evidence, scores it and moves it through
 * Discovered → Evaluated → Needs Review. Critical flags force a manual hold.
 */
final class EvaluationService
{
    private Database $db;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    private function fetcher(): PageFetcher
    {
        return $this->app->make(HttpClient::class);
    }

    /**
     * @param bool $finalAttempt when false, temporary fetch failures throw RetryableException
     * @return array the score result
     */
    public function evaluate(int $prospectId, ?int $userId = null, bool $finalAttempt = true): array
    {
        $prospect = $this->db->fetch('SELECT * FROM prospect_pages WHERE id = ?', [$prospectId]);
        if (!$prospect) {
            throw new \BharatSeo\Jobs\PermanentException('Prospect no longer exists.');
        }
        $project = $this->db->fetch('SELECT * FROM projects WHERE id = ?', [$prospect['project_id']]);
        if (!$project || $project['status'] !== 'active') {
            throw new \BharatSeo\Jobs\PermanentException('Project is not active; evaluation skipped.');
        }
        $projects = $this->app->make(ProjectService::class);
        $excludedDomains = $projects->exclusions($project);
        $domain = (string)$prospect['domain'];
        $excluded = false;
        foreach ($excludedDomains as $ex) {
            if ($domain === $ex || str_ends_with($domain, '.' . $ex)) {
                $excluded = true;
            }
        }

        $response = null;
        $parsed = null;
        if (!$excluded) {
            $this->enforceDailyFetchLimit();
            $response = $this->fetcher()->fetch($prospect['normalized_url']);
            if ($response->isTemporaryFailure() && !$finalAttempt) {
                $this->db->update('prospect_pages', ['fetch_error' => mb_substr((string)($response->error ?? 'HTTP ' . $response->status), 0, 500), 'last_fetched_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$prospectId]);
                throw new RetryableException('Temporary fetch failure: ' . ($response->error ?? 'HTTP ' . $response->status));
            }
            if ($response->ok() && $response->isHtml() && $response->body !== '') {
                $parsed = HtmlParser::parse($response->body, $response->finalUrl);
            }
        }

        $keywords = $projects->keywords((int)$project['id'], 'keyword');
        $topics = $projects->keywords((int)$project['id'], 'topic');
        $targetPage = $prospect['proposed_target_page_id'] ? $this->db->fetch('SELECT * FROM target_pages WHERE id = ?', [$prospect['proposed_target_page_id']]) : null;
        if ($targetPage === null) {
            $targetPage = $this->db->fetch('SELECT * FROM target_pages WHERE project_id = ? ORDER BY priority, id LIMIT 1', [$project['id']]);
        }
        $anchor = $prospect['suggested_anchor'] ?: AnchorSuggester::suggest($project, $targetPage, $keywords, $prospect['category'], (int)$prospect['id']);
        $domainPages = (int)$this->db->fetchColumn('SELECT page_count FROM prospect_domains WHERE id = ?', [$prospect['prospect_domain_id']]);
        $safe = (!$excluded && $parsed !== null) ? $this->app->make(SafeBrowsingClient::class)->check($response->finalUrl) : null;

        $signals = [
            'url' => $prospect['normalized_url'],
            'final_url' => $response?->finalUrl ?? $prospect['normalized_url'],
            'category' => $prospect['category'],
            'attempted' => $response !== null,
            'fetched' => $parsed !== null,
            'http_status' => $response?->status,
            'error_type' => $response?->errorType ?? ($response && !$response->isHtml() ? 'content_type' : null),
            'error' => $response?->error,
            'is_html' => $response?->isHtml() ?? false,
            'response_ms' => $response?->timeMs ?? 0,
            'redirects' => $response?->redirects ?? [],
            'parsed' => $parsed,
            'keywords' => $keywords,
            'topics' => $topics,
            'niche' => $project['niche'],
            'suggested_anchor' => $anchor,
            'domain_page_count' => $domainPages,
            'excluded' => $excluded,
            'blocklisted' => false,
            'own_domain' => Url::belongsToDomain($prospect['normalized_url'], $project['domain']),
            'safe_browsing' => $safe,
        ];
        $result = $this->app->make(QualityScorer::class)->score($signals);

        // Extra evidence for specific opportunity types.
        $evidence = [];
        $flags = $result['flags'];
        if ($parsed !== null) {
            $toProject = HtmlParser::linksTo($parsed, $project['domain']);
            if ($prospect['category'] === 'unlinked_mention' && !empty($project['brand_name'])) {
                $mentions = substr_count(mb_strtolower($parsed['text']), mb_strtolower((string)$project['brand_name']));
                if ($mentions > 0 && $toProject === []) {
                    $evidence[] = sprintf('Page mentions “%s” %d time(s) and does not link to %s (observed %s UTC).', $project['brand_name'], $mentions, $project['domain'], gmdate('Y-m-d'));
                } elseif ($mentions === 0) {
                    $flags[] = ['code' => 'mention_not_found', 'severity' => 'warning', 'message' => 'Brand mention not found on the page; this may not be an unlinked-mention opportunity.'];
                }
            }
            if ($toProject !== []) {
                $flags[] = ['code' => 'already_links', 'severity' => 'info', 'message' => 'Page already links to ' . $project['domain'] . ' (' . count($toProject) . ' link(s)). Consider tracking it in Backlink Monitor instead.'];
            }
            $inContent = count(array_filter($parsed['links'], static fn($l) => $l['external'] && $l['in_content']));
            if (in_array($prospect['category'], ['resource_page', 'partner_page', 'industry_association'], true) && $inContent > 0) {
                $evidence[] = "Page lists $inContent outbound link(s) to external resources in its main content.";
            }
        }

        $contactEmail = $prospect['contact_email'];
        if ($contactEmail === null && $parsed !== null) {
            foreach ($parsed['emails'] as $em) {
                $emDomain = substr((string)strrchr($em, '@'), 1);
                if (Url::registrableDomain($emDomain) === Url::registrableDomain($domain)) {
                    $contactEmail = $em; // publicly listed on the site's own domain
                    break;
                }
            }
        }
        $submissionUrl = $prospect['submission_url'];
        if ($submissionUrl === null && $parsed !== null && in_array($prospect['category'], ['business_directory', 'local_citation', 'community_profile', 'guest_post'], true)) {
            foreach ($parsed['contact_pages'] as $cp) {
                if (preg_match('#submit|add-?listing|write-for-us|contribute|guidelines|register#i', $cp)) {
                    $submissionUrl = $cp;
                    break;
                }
            }
        }
        $siteName = $parsed['site_name'] ?? null;
        if ($siteName === null && !empty($parsed['title'])) {
            $parts = preg_split('/\s[|\-–—:]\s/u', (string)$parsed['title']) ?: [];
            $siteName = count($parts) > 1 ? trim((string)end($parts)) : null;
        }

        $hasCritical = (bool)array_filter($flags, static fn($f) => $f['severity'] === 'critical');
        $total = $result['total'];
        $band = $result['band'];

        $this->db->transaction(function (Database $db) use ($prospect, $project, $result, $flags, $hasCritical, $total, $band, $response, $parsed, $anchor, $evidence, $contactEmail, $submissionUrl, $siteName, $userId, $targetPage): void {
            $locked = $db->fetch('SELECT status, manual_hold, evidence FROM prospect_pages WHERE id = ? FOR UPDATE', [$prospect['id']]);
            $db->run('UPDATE quality_assessments SET is_current = 0 WHERE prospect_page_id = ?', [$prospect['id']]);
            $c = $result['components'];
            $db->insert('quality_assessments', [
                'prospect_page_id' => (int)$prospect['id'],
                'project_id' => (int)$project['id'],
                'client_id' => (int)$project['client_id'],
                'total_score' => $total,
                'relevance_score' => $c['relevance']['score'],
                'editorial_score' => $c['editorial']['score'],
                'trust_score' => $c['trust']['score'],
                'technical_score' => $c['technical']['score'],
                'placement_score' => $c['placement']['score'],
                'band' => $band,
                'components' => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'risk_flags' => json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'has_critical' => $hasCritical ? 1 : 0,
                'scorer_version' => QualityScorer::VERSION,
                'is_current' => 1,
                'assessed_by' => $userId,
            ]);
            $status = $locked['status'];
            $newStatus = $status;
            if (in_array($status, ['discovered', 'evaluated', 'failed', 'needs_review'], true)) {
                $newStatus = ($band !== 'low' || $hasCritical) ? 'needs_review' : 'evaluated';
            }
            $existingEvidence = (string)($locked['evidence'] ?? '');
            foreach ($evidence as $line) {
                if (!str_contains($existingEvidence, mb_substr($line, 0, 40))) {
                    $existingEvidence = trim($existingEvidence . "\n" . $line);
                }
            }
            $update = [
                'quality_score' => $total,
                'score_band' => $band,
                'risk_flags' => json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'has_critical_risk' => $hasCritical ? 1 : 0,
                'status' => $newStatus,
                'suggested_anchor' => mb_substr($anchor, 0, 255),
                'evidence' => $existingEvidence !== '' ? mb_substr($existingEvidence, 0, 5000) : null,
                'last_fetched_at' => $response ? gmdate('Y-m-d H:i:s') : null,
                'http_status' => $response?->status ?: null,
                'final_url' => $response ? mb_substr($response->finalUrl, 0, 2048) : null,
                'content_type' => $response ? mb_substr($response->contentType(), 0, 120) : null,
                'fetch_error' => $response?->error ? mb_substr($response->error, 0, 500) : null,
                'contact_email' => $contactEmail,
                'submission_url' => $submissionUrl ? mb_substr($submissionUrl, 0, 2048) : null,
                'site_name' => $siteName ? mb_substr($siteName, 0, 255) : null,
            ];
            if ($targetPage && !$prospect['proposed_target_page_id']) {
                $update['proposed_target_page_id'] = (int)$targetPage['id'];
            }
            if ($parsed !== null) {
                $update += [
                    'title' => $parsed['title'] !== null ? mb_substr($parsed['title'], 0, 500) : $prospect['title'],
                    'meta_description' => $parsed['description'],
                    'canonical_url' => $parsed['canonical'] ? mb_substr($parsed['canonical'], 0, 2048) : null,
                    'word_count' => $parsed['word_count'],
                    'outbound_link_count' => $parsed['outbound_count'],
                    'contact_page_url' => $parsed['contact_pages'][0] ?? null,
                ];
            }
            if ($hasCritical) {
                $update['manual_hold'] = 1;
                $codes = implode(', ', array_column(array_filter($flags, static fn($f) => $f['severity'] === 'critical'), 'code'));
                $update['hold_reason'] = mb_substr('Critical risk: ' . $codes, 0, 500);
            } elseif ((int)$locked['manual_hold'] === 1 && str_starts_with((string)$prospect['hold_reason'], 'Critical risk')) {
                // risk no longer present after re-evaluation
                $update['manual_hold'] = 0;
                $update['hold_reason'] = null;
            }
            $db->update('prospect_pages', $update, 'id = ?', [$prospect['id']]);
            if ($newStatus !== $status) {
                $this->app->make(AuditLogger::class)->log('prospect.evaluated', $userId, (int)$project['id'], ['from' => $status, 'to' => $newStatus, 'score' => $total, 'critical' => $hasCritical], 'prospect', null, (int)$prospect['id']);
            }
        });
        $result['flags'] = $flags;
        $result['has_critical'] = $hasCritical;
        return $result;
    }

    private function enforceDailyFetchLimit(): void
    {
        $limit = (int)$this->app->settings()->get('crawler.daily_fetch_limit', 2000);
        $ok = $this->app->make(RateLimiter::class)->hit('fetch:daily:' . gmdate('Y-m-d'), $limit, 86400);
        if (!$ok) {
            $secondsToMidnight = 86400 - (time() % 86400) + 60;
            throw new RetryableException('Daily page-fetch limit reached.', $secondsToMidnight);
        }
    }

    public static function responseSummary(HttpResponse $r): string
    {
        return $r->errorType ? ($r->errorType . ': ' . $r->error) : ('HTTP ' . $r->status);
    }
}
