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

/**
 * Observes whether a referring page currently contains a link to the target.
 *
 * Rules:
 *  - Temporary errors (timeouts, 5xx, 429, local rate limit) never change a confirmed link to
 *    lost. After `monitor.failure_threshold` consecutive, spaced failures the link is "broken".
 *  - Negative observations (link missing, page 404/410, pointing elsewhere) need
 *    `monitor.confirmations` consecutive checks spaced at least retry_hours/2 apart before the
 *    status changes. Until then the previous status is kept and a re-check is scheduled.
 *  - This is an observation of fetched HTML by our crawler, not a search-engine index claim.
 */
final class BacklinkMonitor
{
    public const POSITIVE = ['active', 'changed_anchor', 'nofollow', 'redirected'];

    private Database $db;
    private ?PageFetcher $fetcher;

    public function __construct(private App $app, ?PageFetcher $fetcher = null)
    {
        $this->db = $app->db();
        $this->fetcher = $fetcher;
    }

    public function setFetcher(PageFetcher $f): void
    {
        $this->fetcher = $f;
    }

    private function fetcher(): PageFetcher
    {
        return $this->fetcher ??= $this->app->make(HttpClient::class);
    }

    /**
     * Pure observation step (testable without DB).
     * @return array{observation:string, http_status:?int, final_url:?string, link_present:?bool, anchor:?string, rel:?string, nofollow:bool, sponsored:bool, ugc:bool, error:?string, time_ms:int, candidate_status:?string}
     */
    public static function observe(HttpResponse $r, string $referringUrl, string $targetUrl, string $projectDomain, ?string $expectedAnchor): array
    {
        $o = ['observation' => 'unknown', 'http_status' => $r->status ?: null, 'final_url' => $r->finalUrl, 'link_present' => null, 'anchor' => null, 'rel' => null, 'nofollow' => false, 'sponsored' => false, 'ugc' => false, 'error' => $r->error, 'time_ms' => $r->timeMs, 'candidate_status' => null];
        if ($r->errorType === HttpResponse::ERR_ROBOTS || $r->errorType === HttpResponse::ERR_BLOCKED) {
            $o['observation'] = $r->errorType === HttpResponse::ERR_ROBOTS ? 'robots_blocked' : 'url_blocked';
            return $o;
        }
        if ($r->isTemporaryFailure()) {
            $o['observation'] = 'temporary_error';
            $o['error'] ??= 'HTTP ' . $r->status;
            return $o;
        }
        if ($r->errorType !== null) {
            $o['observation'] = 'fetch_error';
            return $o;
        }
        if (in_array($r->status, [404, 410], true) || ($r->status >= 400 && $r->status < 500)) {
            $o['observation'] = 'page_gone';
            $o['link_present'] = false;
            $o['error'] = 'HTTP ' . $r->status;
            return $o;
        }
        if (!$r->isHtml()) {
            $o['observation'] = 'missing';
            $o['link_present'] = false;
            $o['error'] = 'Non-HTML response';
            return $o;
        }
        $parsed = HtmlParser::parse($r->body, $r->finalUrl);
        $links = HtmlParser::linksTo($parsed, $projectDomain, $targetUrl);
        $matching = array_values(array_filter($links, static fn($l) => $l['matches_target']));
        if ($matching === []) {
            $o['link_present'] = false;
            $o['observation'] = $links === [] ? 'missing' : 'not_pointing';
            if ($links !== []) {
                $o['anchor'] = mb_substr($links[0]['anchor'], 0, 500);
                $o['error'] = 'Links to ' . $projectDomain . ' but not to the target URL (e.g. ' . mb_substr($links[0]['href'], 0, 200) . ')';
            }
            return $o;
        }
        // Prefer a visible, followed link if several exist.
        usort($matching, static fn($a, $b) => [$a['hidden'], $a['nofollow'] || $a['sponsored'] || $a['ugc']] <=> [$b['hidden'], $b['nofollow'] || $b['sponsored'] || $b['ugc']]);
        $best = $matching[0];
        $o['link_present'] = true;
        $o['anchor'] = mb_substr($best['anchor'], 0, 500);
        $o['rel'] = $best['rel'] ? mb_substr(implode(' ', $best['rel']), 0, 120) : null;
        $o['nofollow'] = $best['nofollow'];
        $o['sponsored'] = $best['sponsored'];
        $o['ugc'] = $best['ugc'];
        $o['observation'] = 'present';
        if ($best['nofollow'] || $best['sponsored'] || $best['ugc']) {
            $o['candidate_status'] = 'nofollow';
        } elseif ($expectedAnchor !== null && $expectedAnchor !== '' && mb_strtolower(trim($expectedAnchor)) !== mb_strtolower(trim($best['anchor']))) {
            $o['candidate_status'] = 'changed_anchor';
        } elseif ($r->wasRedirected() && !Url::sameResource($r->finalUrl, $referringUrl)) {
            $o['candidate_status'] = 'redirected';
        } else {
            $o['candidate_status'] = 'active';
        }
        if ($best['hidden']) {
            $o['error'] = 'Link appears hidden (inline CSS/attributes).';
        }
        return $o;
    }

    /** Fetch, observe and apply the result to a backlink record. Returns the new status. */
    public function check(int $backlinkId): string
    {
        $rec = $this->db->fetch('SELECT b.*, p.domain AS project_domain, p.status AS project_status FROM backlink_records b JOIN projects p ON p.id = b.project_id WHERE b.id = ?', [$backlinkId]);
        if (!$rec) {
            throw new \BharatSeo\Jobs\PermanentException('Backlink record not found.');
        }
        $resp = $this->fetcher()->fetch($rec['referring_page_url']);
        $obs = self::observe($resp, $rec['referring_page_url'], $rec['target_url'], $rec['project_domain'], $rec['expected_anchor']);
        return $this->apply($backlinkId, $obs);
    }

    public function apply(int $backlinkId, array $obs): string
    {
        $s = $this->app->settings();
        $confirmations = max(2, (int)$s->get('monitor.confirmations', 2));
        $failureThreshold = max(2, (int)$s->get('monitor.failure_threshold', 3));
        $retryHours = max(1, (int)$s->get('monitor.retry_hours', 6));
        $intervalDays = max(1, (int)$s->get('monitor.interval_days', 7));
        $minSpacing = (int)($retryHours * 3600 / 2);
        $now = gmdate('Y-m-d H:i:s');

        $result = $this->db->transaction(function (Database $db) use ($backlinkId, $obs, $confirmations, $failureThreshold, $retryHours, $intervalDays, $minSpacing, $now): array {
            $r = $db->fetch('SELECT * FROM backlink_records WHERE id = ? FOR UPDATE', [$backlinkId]);
            $before = $r['status'];
            $lifecycleBefore = $r['lifecycle'];
            $sinceLast = $r['last_checked_at'] ? time() - strtotime($r['last_checked_at'] . ' UTC') : PHP_INT_MAX;
            $spaced = $sinceLast >= $minSpacing;
            $u = ['last_checked_at' => $now, 'http_status' => $obs['http_status']];
            $note = null;
            $next = time() + $retryHours * 3600;
            $wasConfirmed = $r['lifecycle'] === 'confirmed';

            switch ($obs['observation']) {
                case 'present':
                    $u += [
                        'status' => $obs['candidate_status'],
                        'lifecycle' => 'confirmed',
                        'link_present' => 1,
                        'observed_anchor' => $obs['anchor'],
                        'rel_attributes' => $obs['rel'],
                        'is_nofollow' => (int)$obs['nofollow'],
                        'is_sponsored' => (int)$obs['sponsored'],
                        'is_ugc' => (int)$obs['ugc'],
                        'consecutive_failures' => 0,
                        'consecutive_negative' => 0,
                        'pending_observation' => null,
                        'last_verified_at' => $now,
                        'first_detected_at' => $r['first_detected_at'] ?? $now,
                        'last_error' => $obs['error'],
                    ];
                    $next = time() + $intervalDays * 86400;
                    break;

                case 'temporary_error':
                case 'fetch_error':
                    $count = (int)$r['consecutive_failures'] + (($spaced || (int)$r['consecutive_failures'] === 0) ? 1 : 0);
                    $u['consecutive_failures'] = $count;
                    $u['last_error'] = mb_substr((string)$obs['error'], 0, 500);
                    if ($count >= $failureThreshold) {
                        $u['status'] = 'broken';
                        if ($wasConfirmed) {
                            $u['lifecycle'] = 'lost';
                        }
                        $u['link_present'] = null;
                        $next = time() + 86400;
                    } elseif ($wasConfirmed || in_array($r['status'], self::POSITIVE, true) || $r['status'] === 'temporarily_unavailable') {
                        $u['status'] = 'temporarily_unavailable';
                    }
                    if (!$spaced && (int)$r['consecutive_failures'] > 0) {
                        $note = 'confirmation_too_soon';
                    }
                    break;

                case 'robots_blocked':
                case 'url_blocked':
                    $u['last_error'] = mb_substr((string)($obs['error'] ?? $obs['observation']), 0, 500);
                    $next = time() + 86400;
                    break;

                default: // missing, not_pointing, page_gone
                    $u['link_present'] = 0;
                    $u['last_error'] = $obs['error'] !== null ? mb_substr((string)$obs['error'], 0, 500) : null;
                    $u['consecutive_failures'] = 0;
                    if (!$wasConfirmed && $r['lifecycle'] === 'candidate') {
                        // Never observed yet: nothing to lose, stays unverified.
                        $u['status'] = 'unverified';
                        $u['pending_observation'] = $obs['observation'];
                        $next = time() + 86400;
                        break;
                    }
                    if ($r['lifecycle'] === 'lost' && in_array($r['status'], ['lost', 'broken', 'not_pointing'], true)) {
                        $next = time() + $intervalDays * 86400; // already lost; keep watching for re-detection
                        break;
                    }
                    $count = (int)$r['consecutive_negative'];
                    if ($count === 0 || $spaced) {
                        $count++;
                    } else {
                        $note = 'confirmation_too_soon';
                    }
                    $u['consecutive_negative'] = $count;
                    $u['pending_observation'] = $obs['observation'];
                    if ($count >= $confirmations) {
                        $u['status'] = match ($obs['observation']) {
                            'page_gone' => 'broken',
                            'not_pointing' => 'not_pointing',
                            default => 'lost',
                        };
                        $u['lifecycle'] = $obs['observation'] === 'not_pointing' ? $r['lifecycle'] : 'lost';
                        $next = time() + $intervalDays * 86400;
                    }
                    break;
            }
            $u['next_check_at'] = gmdate('Y-m-d H:i:s', $next);
            $db->update('backlink_records', $u, 'id = ?', [$backlinkId]);
            $after = $u['status'] ?? $before;
            $db->insert('backlink_check_history', [
                'backlink_id' => $backlinkId,
                'project_id' => (int)$r['project_id'],
                'client_id' => (int)$r['client_id'],
                'checked_at' => $now,
                'http_status' => $obs['http_status'],
                'final_url' => $obs['final_url'] !== null ? mb_substr((string)$obs['final_url'], 0, 2048) : null,
                'link_present' => $obs['link_present'] === null ? null : (int)$obs['link_present'],
                'observed_anchor' => $obs['anchor'],
                'rel_attributes' => $obs['rel'],
                'observation' => $obs['observation'] . ($note ? ':' . $note : ''),
                'status_before' => $before,
                'status_after' => $after,
                'status_changed' => $after !== $before ? 1 : 0,
                'error' => $obs['error'] !== null ? mb_substr((string)$obs['error'], 0, 500) : null,
                'response_time_ms' => $obs['time_ms'] ?: null,
            ]);
            return ['before' => $before, 'after' => $after, 'lifecycle_before' => $lifecycleBefore, 'lifecycle_after' => $u['lifecycle'] ?? $lifecycleBefore, 'record' => $r];
        });

        $r = $result['record'];
        if ($result['before'] !== $result['after']) {
            $this->app->make(AuditLogger::class)->log('backlink.status_changed', null, (int)$r['project_id'], ['from' => $result['before'], 'to' => $result['after'], 'observation' => $obs['observation']], 'backlink', null, $backlinkId);
        }
        if ($result['lifecycle_before'] !== 'confirmed' && $result['lifecycle_after'] === 'confirmed') {
            $this->app->make(NotificationService::class)->notifyProject((int)$r['project_id'], 'backlink_confirmed', 'Backlink confirmed on ' . $r['referring_domain'], 'Observed in fetched HTML; status: ' . $result['after'], url('/backlinks/' . $backlinkId), true);
            if ($r['prospect_page_id']) {
                $p = $this->db->fetch('SELECT status FROM prospect_pages WHERE id = ?', [$r['prospect_page_id']]);
                if ($p && in_array($p['status'], ['outreach_sent', 'submitted'], true)) {
                    $this->app->make(ProspectService::class)->transition((int)$r['prospect_page_id'], 'verified', null, 'Backlink observed by monitor');
                }
            }
        }
        if ($result['lifecycle_before'] === 'confirmed' && $result['lifecycle_after'] === 'lost') {
            $this->app->make(NotificationService::class)->notifyProject((int)$r['project_id'], 'backlink_lost', 'Backlink lost on ' . $r['referring_domain'], 'Confirmed after repeated checks: ' . $result['after'], url('/backlinks/' . $backlinkId), true);
        }
        return $result['after'];
    }
}
