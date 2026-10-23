<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Security\Gate;
use BharatSeo\Security\User;

/**
 * Dashboard metrics. Discovered URLs are never counted as acquired backlinks:
 * "confirmed" means our crawler observed the link in fetched HTML.
 */
final class DashboardService
{
    private Database $db;
    private Gate $gate;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
        $this->gate = $app->make(Gate::class);
    }

    public function stats(User $user, array $f): array
    {
        [$scope, $sp] = $this->gate->scope($user, 'x.project_id');
        $projectFilter = '';
        $pp = [];
        if (!empty($f['project_id'])) {
            $projectFilter = ' AND x.project_id = ?';
            $pp[] = (int)$f['project_id'];
        }
        $from = !empty($f['from']) ? local_date_to_utc((string)$f['from']) : null;
        $to = !empty($f['to']) ? local_date_to_utc((string)$f['to'], true) : null;
        $range = function (string $col) use ($from, $to): array {
            $sql = '';
            $p = [];
            if ($from) {
                $sql .= " AND $col >= ?";
                $p[] = $from;
            }
            if ($to) {
                $sql .= " AND $col <= ?";
                $p[] = $to;
            }
            return [$sql, $p];
        };
        $count = function (string $table, string $where = '', array $params = [], ?string $dateCol = null) use ($scope, $sp, $projectFilter, $pp, $range): int {
            [$rs, $rp] = $dateCol ? $range($dateCol) : ['', []];
            return (int)$this->db->fetchColumn("SELECT COUNT(*) FROM $table x WHERE 1=1$scope$projectFilter$where$rs", array_merge($sp, $pp, $params, $rp));
        };

        [$pScope, $pParams] = $this->gate->scope($user, 'p.id');
        $projWhere = $pScope . (!empty($f['project_id']) ? ' AND p.id = ?' : '');
        $projParams = array_merge($pParams, !empty($f['project_id']) ? [(int)$f['project_id']] : []);

        $stats = [
            'projects' => (int)$this->db->fetchColumn("SELECT COUNT(*) FROM projects p WHERE p.status <> 'archived'$projWhere", $projParams),
            'active_clients' => (int)$this->db->fetchColumn("SELECT COUNT(DISTINCT p.client_id) FROM projects p JOIN clients c ON c.id = p.client_id AND c.status = 'active' WHERE p.status = 'active'$projWhere", $projParams),
            'discovered' => $count('prospect_pages', '', [], 'x.discovered_at'),
            'needs_review' => $count('prospect_pages', " AND x.status = 'needs_review'"),
            'on_hold' => $count('prospect_pages', ' AND x.manual_hold = 1'),
            'approved_prospects' => $count('prospect_pages', " AND x.status = 'approved'"),
            'messages_approved' => $count('outreach_messages', " AND x.status = 'approved'"),
            'messages_awaiting' => $count('outreach_messages', " AND x.status = 'needs_review'"),
            'messages_sent' => $count('outreach_messages', " AND x.status = 'sent'", [], 'x.sent_at'),
            'replies' => $count('outreach_messages', " AND x.reply_status IN ('replied','positive')", [], 'x.sent_at'),
            'confirmed' => $count('backlink_records', " AND x.lifecycle = 'confirmed'"),
            'active' => $count('backlink_records', " AND x.status IN ('active','changed_anchor','nofollow','redirected')"),
            'lost' => $count('backlink_records', " AND x.lifecycle = 'lost'"),
            'unverified' => $count('backlink_records', " AND x.status = 'unverified'"),
            'temporarily_unavailable' => $count('backlink_records', " AND x.status = 'temporarily_unavailable'"),
            'avg_score' => (int)round((float)$this->db->fetchColumn("SELECT AVG(x.quality_score) FROM prospect_pages x WHERE x.quality_score IS NOT NULL$scope$projectFilter", array_merge($sp, $pp))),
        ];
        $stats['backlink_status'] = $this->db->fetchPairs("SELECT x.status, COUNT(*) FROM backlink_records x WHERE 1=1$scope$projectFilter GROUP BY x.status", array_merge($sp, $pp));
        $stats['per_project'] = $this->db->fetchAll(
            "SELECT p.id, p.name,
                (SELECT COUNT(*) FROM prospect_pages x WHERE x.project_id = p.id) AS prospects,
                (SELECT COUNT(*) FROM prospect_pages x WHERE x.project_id = p.id AND x.status IN ('approved','outreach_sent','submitted','verified')) AS approved,
                (SELECT COUNT(*) FROM outreach_messages x WHERE x.project_id = p.id AND x.status = 'sent') AS sent,
                (SELECT COUNT(*) FROM backlink_records x WHERE x.project_id = p.id AND x.status IN ('active','changed_anchor','nofollow','redirected')) AS active
             FROM projects p WHERE p.status <> 'archived'$projWhere ORDER BY p.name LIMIT 12",
            $projParams
        );
        return $stats;
    }

    public function activity(User $user, ?int $projectId, int $limit = 15): array
    {
        [$scope, $params] = $this->gate->scope($user, 'a.project_id');
        $sql = "SELECT a.id, a.action, a.entity_type, a.entity_id, a.created_at, a.project_id, u.name AS user_name, p.name AS project_name
                FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN projects p ON p.id = a.project_id WHERE a.project_id IS NOT NULL$scope";
        if ($user->isSuperAdmin() && !$projectId) {
            $sql = 'SELECT a.id, a.action, a.entity_type, a.entity_id, a.created_at, a.project_id, u.name AS user_name, p.name AS project_name
                FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN projects p ON p.id = a.project_id WHERE 1=1';
            $params = [];
        }
        if ($projectId) {
            $sql .= ' AND a.project_id = ?';
            $params[] = $projectId;
        }
        return $this->db->fetchAll($sql . ' ORDER BY a.id DESC LIMIT ' . max(1, $limit), $params);
    }

    public static function actionLabel(string $action): string
    {
        return ucfirst(str_replace(['.', '_'], [' · ', ' '], $action));
    }
}
