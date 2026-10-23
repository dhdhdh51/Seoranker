<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Core\HttpException;
use BharatSeo\Core\ValidationException;
use BharatSeo\Crawler\Url;
use BharatSeo\Security\Gate;
use BharatSeo\Security\User;
use BharatSeo\Support\Labels;
use BharatSeo\Support\Paginator;

/**
 * Prospect (opportunity) persistence, duplicate prevention and the status workflow:
 * Discovered → Evaluated → Needs Review → Approved → Submitted / Outreach Sent → Verified / Rejected / Failed.
 */
final class ProspectService
{
    /** Allowed status transitions. */
    public const TRANSITIONS = [
        'discovered' => ['evaluated', 'needs_review', 'rejected', 'failed'],
        'evaluated' => ['evaluated', 'needs_review', 'rejected', 'failed'],
        'needs_review' => ['evaluated', 'needs_review', 'approved', 'rejected'],
        'approved' => ['outreach_sent', 'submitted', 'rejected', 'needs_review', 'failed'],
        'outreach_sent' => ['verified', 'rejected', 'failed', 'outreach_sent'],
        'submitted' => ['verified', 'rejected', 'failed'],
        'failed' => ['needs_review', 'evaluated', 'rejected'],
        'rejected' => ['needs_review'],
        'verified' => ['outreach_sent'],
    ];

    private Database $db;
    private Gate $gate;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
        $this->gate = $app->make(Gate::class);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Create a prospect from a URL with duplicate prevention. Never throws for duplicates.
     * @return array{status:'created'|'duplicate'|'rejected', id:?int, reason:?string}
     */
    public function createFromUrl(array $project, string $rawUrl, array $meta, ?int $runId, ?int $userId): array
    {
        $url = Url::normalize($rawUrl);
        if ($url === null) {
            return ['status' => 'rejected', 'id' => null, 'reason' => 'Invalid URL'];
        }
        $host = Url::host($url) ?? '';
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || !str_contains($host, '.') || str_contains($host, '_')) {
            return ['status' => 'rejected', 'id' => null, 'reason' => 'URL host must be a public domain name'];
        }
        $parts = parse_url($url);
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['status' => 'rejected', 'id' => null, 'reason' => 'URLs with credentials are not allowed'];
        }
        $domain = Url::domain($url) ?? $host;
        if (Url::belongsToDomain($url, $project['domain'])) {
            return ['status' => 'rejected', 'id' => null, 'reason' => 'URL belongs to the project\'s own website'];
        }
        $category = isset($meta['category']) && isset(Labels::CATEGORIES[$meta['category']]) ? $meta['category'] : 'other';
        $source = isset($meta['source']) && isset(Labels::SOURCES[$meta['source']]) ? $meta['source'] : 'manual';
        $dedupe = Url::dedupeKey($url);
        $contact = isset($meta['contact_email']) && filter_var($meta['contact_email'], FILTER_VALIDATE_EMAIL) ? strtolower((string)$meta['contact_email']) : null;
        $targetId = isset($meta['target_page_id']) ? (int)$meta['target_page_id'] : null;
        if ($targetId === null && !empty($meta['target_url'])) {
            $t = Url::normalize((string)$meta['target_url']);
            if ($t) {
                $targetId = (int)($this->db->fetchColumn('SELECT id FROM target_pages WHERE project_id = ? AND url_hash = ?', [$project['id'], hash('sha256', $t)]) ?? 0) ?: null;
            }
        }
        if ($targetId !== null && !$this->db->fetchColumn('SELECT 1 FROM target_pages WHERE id = ? AND project_id = ?', [$targetId, $project['id']])) {
            $targetId = null;
        }

        try {
            $id = $this->db->transaction(function (Database $db) use ($project, $url, $domain, $dedupe, $category, $source, $meta, $contact, $runId, $userId, $targetId): ?int {
                if ($db->fetchColumn('SELECT id FROM prospect_pages WHERE project_id = ? AND dedupe_key = ?', [$project['id'], $dedupe])) {
                    return null;
                }
                $db->run(
                    'INSERT INTO prospect_domains (project_id, client_id, domain, page_count) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                    [$project['id'], $project['client_id'], $domain]
                );
                $domainId = $db->lastInsertId();
                $id = $db->insert('prospect_pages', [
                    'project_id' => (int)$project['id'],
                    'client_id' => (int)$project['client_id'],
                    'prospect_domain_id' => $domainId,
                    'discovery_run_id' => $runId,
                    'url' => mb_substr(trim($meta['original_url'] ?? $url), 0, 2048),
                    'normalized_url' => $url,
                    'dedupe_key' => $dedupe,
                    'domain' => $domain,
                    'title' => isset($meta['title']) ? mb_substr((string)$meta['title'], 0, 500) : null,
                    'category' => $category,
                    'discovery_source' => $source,
                    'contact_email' => $contact,
                    'contact_name' => isset($meta['contact_name']) && $meta['contact_name'] !== '' ? mb_substr((string)$meta['contact_name'], 0, 160) : null,
                    'evidence' => isset($meta['evidence']) && $meta['evidence'] !== '' ? mb_substr((string)$meta['evidence'], 0, 5000) : null,
                    'notes' => isset($meta['notes']) && $meta['notes'] !== '' ? mb_substr((string)$meta['notes'], 0, 5000) : null,
                    'proposed_target_page_id' => $targetId,
                    'created_by' => $userId,
                    'status' => 'discovered',
                ]);
                $db->run('UPDATE prospect_domains SET page_count = page_count + 1 WHERE id = ?', [$domainId]);
                return $id;
            });
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                return ['status' => 'duplicate', 'id' => null, 'reason' => 'Already in this project'];
            }
            throw $e;
        }
        if ($id === null) {
            return ['status' => 'duplicate', 'id' => null, 'reason' => 'Already in this project'];
        }
        $this->app->make(QueueService::class)->push('evaluate_prospect', ['prospect_id' => $id], (int)$project['id'], 'evaluate:' . $id, 0, 6);
        return ['status' => 'created', 'id' => $id, 'reason' => null];
    }

    /** @return array{rows:array, pager:Paginator} */
    public function list(User $user, array $f, int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = $this->filterSql($user, $f);
        $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM prospect_pages pp $where", $params);
        $pager = new Paginator($total, $page, $perPage);
        $order = match ($f['sort'] ?? '') {
            'score_asc' => 'pp.quality_score IS NULL, pp.quality_score ASC',
            'oldest' => 'pp.discovered_at ASC',
            'newest' => 'pp.discovered_at DESC',
            'domain' => 'pp.domain ASC',
            default => 'pp.quality_score IS NULL, pp.quality_score DESC, pp.id DESC',
        };
        $rows = $this->db->fetchAll(
            "SELECT pp.*, p.name AS project_name FROM prospect_pages pp JOIN projects p ON p.id = pp.project_id $where ORDER BY $order" . $pager->limitSql(),
            $params
        );
        return ['rows' => $rows, 'pager' => $pager];
    }

    /** @return array{0:string,1:array} */
    public function filterSql(User $user, array $f): array
    {
        [$scope, $params] = $this->gate->scope($user, 'pp.project_id');
        $where = 'WHERE 1=1' . $scope;
        if (!empty($f['project_id'])) {
            $where .= ' AND pp.project_id = ?';
            $params[] = (int)$f['project_id'];
        }
        if (!empty($f['status']) && isset(Labels::PROSPECT_STATUSES[$f['status']])) {
            $where .= ' AND pp.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['category']) && isset(Labels::CATEGORIES[$f['category']])) {
            $where .= ' AND pp.category = ?';
            $params[] = $f['category'];
        }
        if (!empty($f['source']) && isset(Labels::SOURCES[$f['source']])) {
            $where .= ' AND pp.discovery_source = ?';
            $params[] = $f['source'];
        }
        if (!empty($f['band']) && in_array($f['band'], ['high', 'review', 'low'], true)) {
            $where .= ' AND pp.score_band = ?';
            $params[] = $f['band'];
        }
        if (($f['risk'] ?? '') === 'critical') {
            $where .= ' AND pp.has_critical_risk = 1';
        } elseif (($f['risk'] ?? '') === 'hold') {
            $where .= ' AND pp.manual_hold = 1';
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr((string)$f['q'], 0, 100)) . '%';
            $where .= ' AND (pp.normalized_url LIKE ? OR pp.title LIKE ? OR pp.domain LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if (!empty($f['from']) && ($u = local_date_to_utc((string)$f['from']))) {
            $where .= ' AND pp.discovered_at >= ?';
            $params[] = $u;
        }
        if (!empty($f['to']) && ($u = local_date_to_utc((string)$f['to'], true))) {
            $where .= ' AND pp.discovered_at <= ?';
            $params[] = $u;
        }
        return [$where, $params];
    }

    public function find(User $user, int $id): array
    {
        [$scope, $params] = $this->gate->scope($user, 'pp.project_id');
        $row = $this->db->fetch(
            "SELECT pp.*, p.name AS project_name, p.domain AS project_domain, p.approval_policy, tp.url AS target_url
             FROM prospect_pages pp JOIN projects p ON p.id = pp.project_id LEFT JOIN target_pages tp ON tp.id = pp.proposed_target_page_id
             WHERE pp.id = ?$scope",
            array_merge([$id], $params)
        );
        if (!$row) {
            throw new HttpException(404);
        }
        return $row;
    }

    public function get(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM prospect_pages WHERE id = ?', [$id]);
    }

    /** Transition with workflow validation (row-locked). */
    public function transition(int $id, string $to, ?int $userId, ?string $reason = null, array $extra = []): void
    {
        $this->db->transaction(function (Database $db) use ($id, $to, $userId, $reason, $extra): void {
            $row = $db->fetch('SELECT id, project_id, status, manual_hold FROM prospect_pages WHERE id = ? FOR UPDATE', [$id]);
            if (!$row) {
                throw new HttpException(404);
            }
            if (!self::canTransition($row['status'], $to)) {
                throw ValidationException::single('status', sprintf('Cannot move a prospect from “%s” to “%s”.', Labels::PROSPECT_STATUSES[$row['status']] ?? $row['status'], Labels::PROSPECT_STATUSES[$to] ?? $to));
            }
            if ($to === 'approved' && (int)$row['manual_hold'] === 1) {
                throw ValidationException::single('status', 'This prospect is on manual hold because of a critical risk flag. Release the hold with a documented reason first.');
            }
            $db->update('prospect_pages', ['status' => $to, 'status_reason' => $reason !== null ? mb_substr($reason, 0, 500) : null] + $extra, 'id = ?', [$id]);
            $this->app->make(AuditLogger::class)->log('prospect.status_changed', $userId, (int)$row['project_id'], ['from' => $row['status'], 'to' => $to, 'reason' => $reason], 'prospect', null, $id);
        });
    }

    public function update(User $user, int $id, array $input): void
    {
        $p = $this->find($user, $id);
        $this->gate->authorizeProject($user, (int)$p['project_id'], Gate::MANAGE);
        $category = $input['category'] ?? $p['category'];
        if (!isset(Labels::CATEGORIES[$category])) {
            throw ValidationException::single('category', 'Invalid category.');
        }
        $contact = trim((string)($input['contact_email'] ?? ''));
        if ($contact !== '' && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::single('contact_email', 'Contact email is invalid.');
        }
        $submission = trim((string)($input['submission_url'] ?? ''));
        $submissionN = $submission === '' ? null : Url::normalize($submission);
        if ($submission !== '' && $submissionN === null) {
            throw ValidationException::single('submission_url', 'Submission URL must be an http(s) URL.');
        }
        $target = (int)($input['proposed_target_page_id'] ?? 0) ?: null;
        if ($target && !$this->db->fetchColumn('SELECT 1 FROM target_pages WHERE id = ? AND project_id = ?', [$target, $p['project_id']])) {
            throw ValidationException::single('proposed_target_page_id', 'Invalid target page.');
        }
        $anchor = mb_substr(trim((string)($input['suggested_anchor'] ?? '')), 0, 255);
        $this->db->update('prospect_pages', [
            'category' => $category,
            'contact_email' => $contact === '' ? null : strtolower($contact),
            'contact_name' => mb_substr(trim((string)($input['contact_name'] ?? '')), 0, 160) ?: null,
            'submission_url' => $submissionN,
            'evidence' => mb_substr(trim((string)($input['evidence'] ?? '')), 0, 5000) ?: null,
            'notes' => mb_substr(trim((string)($input['notes'] ?? '')), 0, 5000) ?: null,
            'proposed_target_page_id' => $target,
            'suggested_anchor' => $anchor === '' ? null : $anchor,
        ], 'id = ?', [$id]);
        $this->app->make(AuditLogger::class)->log('prospect.updated', $user->id, (int)$p['project_id'], [], 'prospect', null, $id);
    }

    public function releaseHold(User $user, int $id, string $reason): void
    {
        $p = $this->find($user, $id);
        $this->gate->authorizeProject($user, (int)$p['project_id'], Gate::MANAGE);
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::single('reason', 'Explain (at least 10 characters) why the critical risk is acceptable.');
        }
        $this->db->update('prospect_pages', ['manual_hold' => 0, 'hold_reason' => mb_substr('Released: ' . trim($reason), 0, 500)], 'id = ?', [$id]);
        $this->app->make(AuditLogger::class)->log('prospect.hold_released', $user->id, (int)$p['project_id'], ['reason' => $reason, 'flags' => $p['risk_flags']], 'prospect', null, $id);
    }

    /**
     * Record a manual submission made by the user on the site's official submission page.
     */
    public function markSubmitted(User $user, int $id, ?string $externalId, ?string $note): void
    {
        $p = $this->find($user, $id);
        $this->gate->authorizeProject($user, (int)$p['project_id'], Gate::MANAGE);
        $externalId = $externalId !== null && trim($externalId) !== '' ? mb_substr(trim($externalId), 0, 190) : null;
        try {
            $this->transition($id, 'submitted', $user->id, $note ?: 'Submitted manually via official submission page', ['external_submission_id' => $externalId]);
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw ValidationException::single('external_submission_id', 'This submission reference is already recorded for another prospect in this project.');
            }
            throw $e;
        }
        $this->app->make(AuditLogger::class)->log('prospect.submission_recorded', $user->id, (int)$p['project_id'], ['external_id' => $externalId, 'submission_url' => $p['submission_url']], 'prospect', null, $id);
    }

    public function assessments(int $prospectId): array
    {
        return $this->db->fetchAll('SELECT qa.*, u.name AS assessed_by_name FROM quality_assessments qa LEFT JOIN users u ON u.id = qa.assessed_by WHERE qa.prospect_page_id = ? ORDER BY qa.id DESC LIMIT 20', [$prospectId]);
    }

    public function timeline(int $prospectId): array
    {
        return $this->db->fetchAll("SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.entity_type = 'prospect' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 50", [$prospectId]);
    }

    /** Requeue evaluation (idempotent via unique key). */
    public function requeueEvaluation(User $user, int $id): void
    {
        $p = $this->find($user, $id);
        $this->gate->authorizeProject($user, (int)$p['project_id'], Gate::MANAGE);
        $this->app->make(QueueService::class)->push('evaluate_prospect', ['prospect_id' => $id, 'manual' => true, 'user_id' => $user->id], (int)$p['project_id'], 'evaluate:' . $id, 0, 3);
    }
}
