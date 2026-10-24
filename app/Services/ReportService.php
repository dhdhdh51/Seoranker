<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Security\Gate;
use BharatSeo\Security\User;
use BharatSeo\Support\Csv;
use BharatSeo\Support\Labels;

/**
 * Project reports and CSV exports, generated from actual database records.
 */
final class ReportService
{
    public const EXPORT_LIMIT = 50000;

    private Database $db;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    public function projectReport(User $user, int $projectId, ?string $from = null, ?string $to = null): array
    {
        $project = $this->app->make(ProjectService::class)->find($user, $projectId, Gate::VIEW);
        $fromUtc = $from ? local_date_to_utc($from) : null;
        $toUtc = $to ? local_date_to_utc($to, true) : null;
        $q = fn(string $sql, array $p = []) => $this->db->fetchAll($sql, array_merge([$projectId], $p));
        $c = fn(string $sql, array $p = []) => (int)$this->db->fetchColumn($sql, array_merge([$projectId], $p));
        [$rangeSql, $rangeParams] = $this->range('discovered_at', $fromUtc, $toUtc);
        [$sentRange, $sentParams] = $this->range('sent_at', $fromUtc, $toUtc);
        [$histRange, $histParams] = $this->range('h.checked_at', $fromUtc, $toUtc);
        [$firstRange, $firstParams] = $this->range('first_detected_at', $fromUtc, $toUtc);

        $scoreBuckets = ['80–100' => 0, '60–79' => 0, '40–59' => 0, '20–39' => 0, '0–19' => 0, 'Not scored' => 0];
        foreach ($q("SELECT quality_score FROM prospect_pages WHERE project_id = ?$rangeSql", $rangeParams) as $r) {
            $s = $r['quality_score'];
            $key = $s === null ? 'Not scored' : ($s >= 80 ? '80–100' : ($s >= 60 ? '60–79' : ($s >= 40 ? '40–59' : ($s >= 20 ? '20–39' : '0–19'))));
            $scoreBuckets[$key]++;
        }
        $attr = $this->db->fetch(
            "SELECT SUM(is_nofollow = 0 AND is_sponsored = 0 AND is_ugc = 0) AS followed, SUM(is_nofollow) AS nofollow, SUM(is_sponsored) AS sponsored, SUM(is_ugc) AS ugc
             FROM backlink_records WHERE project_id = ? AND link_present = 1 AND status IN ('active','changed_anchor','nofollow','redirected')",
            [$projectId]
        ) ?? [];

        return [
            'project' => $project,
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'from' => $from,
            'to' => $to,
            'by_category' => $q("SELECT category, COUNT(*) AS n FROM prospect_pages WHERE project_id = ?$rangeSql GROUP BY category ORDER BY n DESC", $rangeParams),
            'by_status' => $q("SELECT status, COUNT(*) AS n FROM prospect_pages WHERE project_id = ?$rangeSql GROUP BY status ORDER BY n DESC", $rangeParams),
            'awaiting_review' => $c("SELECT COUNT(*) FROM prospect_pages WHERE project_id = ? AND status = 'needs_review'"),
            'on_hold' => $c('SELECT COUNT(*) FROM prospect_pages WHERE project_id = ? AND manual_hold = 1'),
            'approved' => $c("SELECT COUNT(*) FROM prospect_pages WHERE project_id = ? AND status = 'approved'"),
            'outreach_sent' => $c("SELECT COUNT(*) FROM outreach_messages WHERE project_id = ? AND status = 'sent'$sentRange", $sentParams),
            'reply_status' => $q("SELECT reply_status, COUNT(*) AS n FROM outreach_messages WHERE project_id = ? AND status = 'sent'$sentRange GROUP BY reply_status", $sentParams),
            'active_backlinks' => $c("SELECT COUNT(*) FROM backlink_records WHERE project_id = ? AND status IN ('active','changed_anchor','nofollow','redirected')"),
            'confirmed_backlinks' => $c("SELECT COUNT(*) FROM backlink_records WHERE project_id = ? AND lifecycle = 'confirmed'"),
            'lost_backlinks' => $c("SELECT COUNT(*) FROM backlink_records WHERE project_id = ? AND lifecycle = 'lost'"),
            'unverified_backlinks' => $c("SELECT COUNT(*) FROM backlink_records WHERE project_id = ? AND status = 'unverified'"),
            'new_backlinks' => $c("SELECT COUNT(*) FROM backlink_records WHERE project_id = ? AND first_detected_at IS NOT NULL$firstRange", $firstParams),
            'backlink_status' => $q('SELECT status, COUNT(*) AS n FROM backlink_records WHERE project_id = ? GROUP BY status ORDER BY n DESC'),
            'changes' => $q("SELECT h.checked_at, h.status_before, h.status_after, h.observation, b.referring_page_url FROM backlink_check_history h JOIN backlink_records b ON b.id = h.backlink_id WHERE h.project_id = ? AND h.status_changed = 1$histRange ORDER BY h.id DESC LIMIT 100", $histParams),
            'score_distribution' => $scoreBuckets,
            'link_attributes' => ['Followed (no rel qualifier)' => (int)($attr['followed'] ?? 0), 'nofollow' => (int)($attr['nofollow'] ?? 0), 'sponsored' => (int)($attr['sponsored'] ?? 0), 'ugc' => (int)($attr['ugc'] ?? 0)],
            'last_monitoring' => $this->db->fetchColumn('SELECT MAX(last_checked_at) FROM backlink_records WHERE project_id = ?', [$projectId]),
            'failed_jobs' => $c('SELECT COUNT(*) FROM failed_jobs WHERE project_id = ? AND resolved_at IS NULL'),
            'failed_messages' => $c("SELECT COUNT(*) FROM outreach_messages WHERE project_id = ? AND status = 'failed'"),
            'temporarily_unavailable' => $c("SELECT COUNT(*) FROM backlink_records WHERE project_id = ? AND status = 'temporarily_unavailable'"),
            'cron_active' => $this->app->make(CronService::class)->isAutomationActive(),
        ];
    }

    private function range(string $col, ?string $from, ?string $to): array
    {
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
    }

    /** Stream a CSV export to php://output (or $out). Returns row count. */
    public function export(User $user, string $type, array $f, $out): int
    {
        $gate = $this->app->make(Gate::class);
        $projectId = !empty($f['project_id']) ? (int)$f['project_id'] : null;
        if ($projectId) {
            $gate->authorizeProject($user, $projectId, Gate::EXPORT);
        } elseif ($user->isClient()) {
            // Clients may only export projects where they hold the export permission.
            $allowed = array_filter($gate->projectIds($user) ?? [], fn($id) => $gate->can($user, Gate::EXPORT, $id));
            if ($allowed === []) {
                throw new \BharatSeo\Core\HttpException(403);
            }
        }
        $rows = 0;
        $write = static function (array $r) use ($out, &$rows): void {
            Csv::writeRow($out, $r);
            $rows++;
        };
        $limit = ' LIMIT ' . self::EXPORT_LIMIT;
        switch ($type) {
            case 'prospects':
                [$where, $params] = $this->app->make(ProspectService::class)->filterSql($user, $f);
                $where .= $this->clientExportFilter($user, 'pp.project_id');
                Csv::writeRow($out, ['ID', 'Project', 'URL', 'Domain', 'Title', 'Category', 'Source', 'Discovered (UTC)', 'HTTP status', 'Score', 'Band', 'Critical risk', 'Manual hold', 'Risk flags', 'Status', 'Contact email', 'Suggested anchor', 'Target page', 'Evidence']);
                $stmt = $this->db->run("SELECT pp.*, p.name AS project_name, tp.url AS target_url FROM prospect_pages pp JOIN projects p ON p.id = pp.project_id LEFT JOIN target_pages tp ON tp.id = pp.proposed_target_page_id $where ORDER BY pp.id$limit", $params);
                while ($r = $stmt->fetch()) {
                    $flags = implode('; ', array_map(static fn($x) => $x['severity'] . ':' . $x['code'], json_decode((string)$r['risk_flags'], true) ?: []));
                    $write([$r['id'], $r['project_name'], $r['normalized_url'], $r['domain'], $r['title'], Labels::category($r['category']), Labels::SOURCES[$r['discovery_source']] ?? $r['discovery_source'], $r['discovered_at'], $r['http_status'], $r['quality_score'], $r['score_band'], $r['has_critical_risk'] ? 'yes' : 'no', $r['manual_hold'] ? 'yes' : 'no', $flags, Labels::PROSPECT_STATUSES[$r['status']] ?? $r['status'], $user->isClient() ? '' : $r['contact_email'], $r['suggested_anchor'], $r['target_url'], $r['evidence']]);
                }
                break;
            case 'backlinks':
                [$where, $params] = $this->app->make(BacklinkService::class)->filterSql($user, $f);
                $where .= $this->clientExportFilter($user, 'b.project_id');
                Csv::writeRow($out, ['ID', 'Project', 'Referring page', 'Referring domain', 'Target URL', 'Expected anchor', 'Observed anchor', 'HTTP status', 'rel', 'Link present in fetched HTML', 'Status', 'Lifecycle', 'First detected (UTC)', 'Last verified (UTC)', 'Last checked (UTC)', 'Last error', 'Note']);
                $stmt = $this->db->run("SELECT b.*, p.name AS project_name FROM backlink_records b JOIN projects p ON p.id = b.project_id $where ORDER BY b.id$limit", $params);
                while ($r = $stmt->fetch()) {
                    $write([$r['id'], $r['project_name'], $r['referring_page_url'], $r['referring_domain'], $r['target_url'], $r['expected_anchor'], $r['observed_anchor'], $r['http_status'], $r['rel_attributes'], $r['link_present'] === null ? 'unknown' : ($r['link_present'] ? 'yes' : 'no'), Labels::BACKLINK_STATUSES[$r['status']] ?? $r['status'], Labels::LIFECYCLE[$r['lifecycle']] ?? $r['lifecycle'], $r['first_detected_at'], $r['last_verified_at'], $r['last_checked_at'], $r['last_error'], 'Observed by ' . brand() . ' crawler; not a search-engine index claim']);
                }
                break;
            case 'history':
                [$scope, $params] = $gate->scope($user, 'h.project_id');
                $scope .= $this->clientExportFilter($user, 'h.project_id');
                $sql = "SELECT h.*, b.referring_page_url, b.target_url FROM backlink_check_history h JOIN backlink_records b ON b.id = h.backlink_id WHERE 1=1$scope";
                if ($projectId) {
                    $sql .= ' AND h.project_id = ?';
                    $params[] = $projectId;
                }
                Csv::writeRow($out, ['Checked (UTC)', 'Referring page', 'Target URL', 'HTTP status', 'Final URL', 'Link present', 'Observed anchor', 'rel', 'Observation', 'Status before', 'Status after', 'Error']);
                $stmt = $this->db->run($sql . ' ORDER BY h.id DESC' . $limit, $params);
                while ($r = $stmt->fetch()) {
                    $write([$r['checked_at'], $r['referring_page_url'], $r['target_url'], $r['http_status'], $r['final_url'], $r['link_present'] === null ? 'unknown' : ($r['link_present'] ? 'yes' : 'no'), $r['observed_anchor'], $r['rel_attributes'], $r['observation'], $r['status_before'], $r['status_after'], $r['error']]);
                }
                break;
            case 'drafts':
                [$scope, $params] = $gate->scope($user, 'm.project_id');
                $scope .= $this->clientExportFilter($user, 'm.project_id');
                $sql = "SELECT m.*, c.name AS campaign_name, pp.normalized_url FROM outreach_messages m JOIN outreach_campaigns c ON c.id = m.campaign_id JOIN prospect_pages pp ON pp.id = m.prospect_page_id WHERE 1=1$scope";
                if ($projectId) {
                    $sql .= ' AND m.project_id = ?';
                    $params[] = $projectId;
                }
                if (!empty($f['campaign_id'])) {
                    $sql .= ' AND m.campaign_id = ?';
                    $params[] = (int)$f['campaign_id'];
                }
                Csv::writeRow($out, ['ID', 'Campaign', 'Prospect URL', 'Recipient', 'Subject', 'Body', 'Status', 'Type', 'Revision', 'Generator', 'Sent (UTC)', 'Reply status']);
                $stmt = $this->db->run($sql . ' ORDER BY m.id' . $limit, $params);
                while ($r = $stmt->fetch()) {
                    $write([$r['id'], $r['campaign_name'], $r['normalized_url'], $user->isClient() ? '' : $r['recipient_email'], $r['subject'], $r['body'], $r['status'], $r['message_type'], $r['revision'], $r['generator'], $r['sent_at'], $r['reply_status']]);
                }
                break;
            case 'report':
                if (!$projectId) {
                    throw new \BharatSeo\Core\ValidationException(['project_id' => 'Select a project.']);
                }
                $rep = $this->projectReport($user, $projectId, $f['from'] ?? null, $f['to'] ?? null);
                $write([brand() . ' project report', $rep['project']['name']]);
                $write(['Generated', fmt_dt($rep['generated_at'], 'Y-m-d H:i T')]);
                $write(['Period', ($rep['from'] ?: 'all time') . ' – ' . ($rep['to'] ?: 'today')]);
                $write([]);
                $write(['Metric', 'Value']);
                foreach (['awaiting_review' => 'Opportunities awaiting review', 'on_hold' => 'Opportunities on manual hold', 'approved' => 'Approved opportunities', 'outreach_sent' => 'Outreach emails sent', 'active_backlinks' => 'Active backlinks (observed)', 'confirmed_backlinks' => 'Confirmed backlinks', 'lost_backlinks' => 'Lost backlinks', 'unverified_backlinks' => 'Unverified backlinks', 'new_backlinks' => 'Newly detected in period', 'failed_jobs' => 'Unresolved failed jobs', 'failed_messages' => 'Failed outreach messages', 'temporarily_unavailable' => 'Links temporarily unavailable'] as $k => $label) {
                    $write([$label, $rep[$k]]);
                }
                $write(['Last monitoring check', $rep['last_monitoring'] ? fmt_dt($rep['last_monitoring']) : 'never']);
                $write(['Automation (cron) active', $rep['cron_active'] ? 'yes' : 'NO — cron not running']);
                $write([]);
                $write(['Prospects by category', 'Count']);
                foreach ($rep['by_category'] as $r) {
                    $write([Labels::category($r['category']), $r['n']]);
                }
                $write([]);
                $write(['Reply status', 'Count']);
                foreach ($rep['reply_status'] as $r) {
                    $write([$r['reply_status'], $r['n']]);
                }
                $write([]);
                $write(['Quality score band', 'Count']);
                foreach ($rep['score_distribution'] as $k => $v) {
                    $write([$k, $v]);
                }
                $write([]);
                $write(['Link attribute', 'Count']);
                foreach ($rep['link_attributes'] as $k => $v) {
                    $write([$k, $v]);
                }
                $write([]);
                $write(['Note', 'Scores are internal heuristics, not Google PageRank/DA/DR. Backlinks are observations of fetched HTML, not search-engine index data.']);
                break;
            default:
                throw new \BharatSeo\Core\HttpException(404);
        }
        $this->db->insert('export_jobs', [
            'project_id' => $projectId,
            'client_id' => $projectId ? (int)$this->db->fetchColumn('SELECT client_id FROM projects WHERE id = ?', [$projectId]) : null,
            'user_id' => $user->id,
            'type' => $type,
            'format' => 'csv',
            'row_count' => $rows,
            'filters' => mb_substr(json_encode(array_filter($f)), 0, 1000),
        ]);
        $this->app->make(AuditLogger::class)->log('export.' . $type, $user->id, $projectId, ['rows' => $rows]);
        return $rows;
    }

    /** For clients: restrict to projects where they have the export permission. */
    private function clientExportFilter(User $user, string $col): string
    {
        if (!$user->isClient()) {
            return '';
        }
        $gate = $this->app->make(Gate::class);
        $ids = array_values(array_filter($gate->projectIds($user) ?? [], fn($id) => $gate->can($user, Gate::EXPORT, $id)));
        return $ids === [] ? ' AND 1 = 0' : " AND $col IN (" . implode(',', array_map('intval', $ids)) . ')';
    }
}
