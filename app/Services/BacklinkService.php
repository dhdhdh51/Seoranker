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
use BharatSeo\Support\Validator;

final class BacklinkService
{
    private Database $db;
    private Gate $gate;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
        $this->gate = $app->make(Gate::class);
    }

    /** @return array{created:int, duplicate:int, rejected:int, errors:string[]} */
    public function addCandidates(User $user, int $projectId, array $input): array
    {
        $project = $this->app->make(ProjectService::class)->find($user, $projectId, Gate::MANAGE);
        $target = Url::normalize((string)($input['target_url'] ?? ''));
        if ($target === null || !Url::belongsToDomain($target, $project['domain'])) {
            throw ValidationException::single('target_url', 'Target URL must be a page on ' . $project['domain'] . '.');
        }
        $lines = Validator::lines((string)($input['referring_urls'] ?? ''), 201);
        if ($lines === [] || count($lines) > 200) {
            throw ValidationException::single('referring_urls', 'Enter between 1 and 200 referring page URLs.');
        }
        $anchor = mb_substr(trim((string)($input['expected_anchor'] ?? '')), 0, 255) ?: null;
        $res = ['created' => 0, 'duplicate' => 0, 'rejected' => 0, 'errors' => []];
        foreach ($lines as $line) {
            $r = $this->createRecord($project, $line, $target, $anchor, 'manual', null, $user->id);
            $res[$r['status']]++;
            if ($r['status'] === 'rejected') {
                $res['errors'][] = mb_substr($line, 0, 100) . ': ' . $r['reason'];
            }
        }
        $this->app->make(AuditLogger::class)->log('backlink.candidates_added', $user->id, $projectId, array_diff_key($res, ['errors' => 1]), 'project', null, $projectId);
        return $res;
    }

    /** @return array{status:string, id:?int, reason:?string} */
    public function createRecord(array $project, string $referring, string $target, ?string $anchor, string $source, ?int $prospectId, ?int $userId, ?string $externalId = null): array
    {
        $ref = Url::normalize($referring);
        if ($ref === null) {
            return ['status' => 'rejected', 'id' => null, 'reason' => 'Invalid URL'];
        }
        if (Url::belongsToDomain($ref, $project['domain'])) {
            return ['status' => 'rejected', 'id' => null, 'reason' => 'Referring page is on the project\'s own domain'];
        }
        $refDomain = Url::domain($ref) ?? '';
        try {
            $id = $this->db->insert('backlink_records', [
                'project_id' => (int)$project['id'],
                'client_id' => (int)$project['client_id'],
                'prospect_page_id' => $prospectId,
                'referring_page_url' => $ref,
                'referring_url_hash' => Url::dedupeKey($ref),
                'referring_domain' => $refDomain,
                'target_url' => $target,
                'target_url_hash' => Url::dedupeKey($target),
                'expected_anchor' => $anchor,
                'status' => 'unverified',
                'lifecycle' => 'candidate',
                'next_check_at' => gmdate('Y-m-d H:i:s'),
                'source' => $source,
                'external_submission_id' => $externalId,
                'created_by' => $userId,
            ]);
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                return ['status' => 'duplicate', 'id' => null, 'reason' => 'Already tracked'];
            }
            throw $e;
        }
        $this->app->make(QueueService::class)->push('check_backlink', ['backlink_id' => $id], (int)$project['id'], 'backlink:' . $id, 30, 4);
        return ['status' => 'created', 'id' => $id, 'reason' => null];
    }

    /** Start tracking the link expected from an approved/contacted prospect. */
    public function trackFromProspect(User $user, int $prospectId): int
    {
        $p = $this->app->make(ProspectService::class)->find($user, $prospectId);
        $project = $this->app->make(ProjectService::class)->find($user, (int)$p['project_id'], Gate::MANAGE);
        $target = $p['target_url'] ?: $project['website_url'];
        $source = $p['status'] === 'submitted' ? 'submission' : 'outreach';
        $r = $this->createRecord($project, $p['final_url'] ?: $p['normalized_url'], $target, $p['suggested_anchor'], $source, $prospectId, $user->id, $p['external_submission_id']);
        if ($r['status'] === 'duplicate') {
            $id = (int)$this->db->fetchColumn('SELECT id FROM backlink_records WHERE project_id = ? AND referring_url_hash = ? AND target_url_hash = ?', [$project['id'], Url::dedupeKey(Url::normalize($p['final_url'] ?: $p['normalized_url']) ?? ''), Url::dedupeKey(Url::normalize($target) ?? $target)]);
            if ($id) {
                return $id;
            }
        }
        if ($r['status'] !== 'created') {
            throw ValidationException::single('prospect', 'Cannot track this prospect: ' . $r['reason']);
        }
        $this->app->make(AuditLogger::class)->log('backlink.tracking_started', $user->id, (int)$project['id'], ['prospect_id' => $prospectId], 'backlink', null, $r['id']);
        return (int)$r['id'];
    }

    /** @return array{rows:array, pager:Paginator} */
    public function list(User $user, array $f, int $page = 1): array
    {
        [$where, $params] = $this->filterSql($user, $f);
        $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM backlink_records b $where", $params);
        $pager = new Paginator($total, $page, 25);
        $rows = $this->db->fetchAll("SELECT b.*, p.name AS project_name FROM backlink_records b JOIN projects p ON p.id = b.project_id $where ORDER BY b.updated_at DESC, b.id DESC" . $pager->limitSql(), $params);
        return ['rows' => $rows, 'pager' => $pager];
    }

    /** @return array{0:string,1:array} */
    public function filterSql(User $user, array $f): array
    {
        [$scope, $params] = $this->gate->scope($user, 'b.project_id');
        $where = 'WHERE 1=1' . $scope;
        if (!empty($f['project_id'])) {
            $where .= ' AND b.project_id = ?';
            $params[] = (int)$f['project_id'];
        }
        if (!empty($f['status']) && isset(Labels::BACKLINK_STATUSES[$f['status']])) {
            $where .= ' AND b.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['lifecycle']) && isset(Labels::LIFECYCLE[$f['lifecycle']])) {
            $where .= ' AND b.lifecycle = ?';
            $params[] = $f['lifecycle'];
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr((string)$f['q'], 0, 100)) . '%';
            $where .= ' AND (b.referring_page_url LIKE ? OR b.target_url LIKE ?)';
            array_push($params, $like, $like);
        }
        return [$where, $params];
    }

    public function find(User $user, int $id): array
    {
        [$scope, $params] = $this->gate->scope($user, 'b.project_id');
        $r = $this->db->fetch("SELECT b.*, p.name AS project_name, p.domain AS project_domain FROM backlink_records b JOIN projects p ON p.id = b.project_id WHERE b.id = ?$scope", array_merge([$id], $params));
        if (!$r) {
            throw new HttpException(404);
        }
        return $r;
    }

    public function history(int $id, int $limit = 100): array
    {
        return $this->db->fetchAll('SELECT * FROM backlink_check_history WHERE backlink_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$id]);
    }

    public function checkNow(User $user, int $id): void
    {
        $b = $this->find($user, $id);
        $this->gate->authorizeProject($user, (int)$b['project_id'], Gate::MANAGE);
        $this->app->make(QueueService::class)->push('check_backlink', ['backlink_id' => $id], (int)$b['project_id'], 'backlink:' . $id, 0, 2);
    }

    public function updateRecord(User $user, int $id, array $input): void
    {
        $b = $this->find($user, $id);
        $this->gate->authorizeProject($user, (int)$b['project_id'], Gate::MANAGE);
        $anchor = mb_substr(trim((string)($input['expected_anchor'] ?? '')), 0, 255) ?: null;
        $this->db->update('backlink_records', ['expected_anchor' => $anchor], 'id = ?', [$id]);
        $this->app->make(AuditLogger::class)->log('backlink.updated', $user->id, (int)$b['project_id'], ['expected_anchor' => $anchor], 'backlink', null, $id);
    }

    public function delete(User $user, int $id): void
    {
        $b = $this->find($user, $id);
        $this->gate->authorizeProject($user, (int)$b['project_id'], Gate::MANAGE);
        $this->db->run('DELETE FROM backlink_records WHERE id = ?', [$id]);
        $this->app->make(AuditLogger::class)->log('backlink.deleted', $user->id, (int)$b['project_id'], ['referring' => $b['referring_page_url'], 'target' => $b['target_url'], 'status' => $b['status']], 'backlink', null, $id);
    }

    /** Enqueue checks that are due (bounded). Returns number enqueued. */
    public function enqueueDue(int $limit): int
    {
        $rows = $this->db->fetchAll(
            "SELECT b.id, b.project_id FROM backlink_records b JOIN projects p ON p.id = b.project_id AND p.status = 'active'
             WHERE b.next_check_at IS NOT NULL AND b.next_check_at <= UTC_TIMESTAMP() ORDER BY b.next_check_at ASC LIMIT " . max(1, $limit)
        );
        $q = $this->app->make(QueueService::class);
        $n = 0;
        foreach ($rows as $r) {
            if ($q->push('check_backlink', ['backlink_id' => (int)$r['id']], (int)$r['project_id'], 'backlink:' . $r['id'], 0, 4) > 0) {
                $n++;
            }
        }
        return $n;
    }
}
