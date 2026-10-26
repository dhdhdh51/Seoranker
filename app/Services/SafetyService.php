<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\Database;
use Flatmates\Core\HttpException;
use Flatmates\Core\ValidationException;
use Flatmates\Security\RateLimiter;
use Flatmates\Security\User;
use Flatmates\Support\Options;

/** Blocking, reporting and the moderation queue. */
final class SafetyService
{
    /** Reasons that are escalated to "serious" automatically. */
    public const SERIOUS = ['harassment', 'scam', 'safety', 'discrimination'];

    public function __construct(private App $app)
    {
    }

    public function blockedEitherWay(int $a, int $b): bool
    {
        return (bool)$this->app->db()->fetchColumn('SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1', [$a, $b, $b, $a]);
    }

    /** Blocking hides both users from each other everywhere and ends any match. */
    public function block(User $u, int $targetId): void
    {
        if ($targetId === $u->id || !$this->app->db()->fetchColumn('SELECT 1 FROM users WHERE id = ?', [$targetId])) {
            throw new HttpException(404);
        }
        $this->app->db()->transaction(function (Database $db) use ($u, $targetId): void {
            $db->run('INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)', [$u->id, $targetId]);
            $this->app->make(MatchService::class)->endMatch($u->id, $targetId, $u->id);
        });
        $this->app->make(AuditLog::class)->log('safety.block', $u->id, 'user', $targetId);
    }

    public function unblock(User $u, int $targetId): void
    {
        $this->app->db()->run('DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?', [$u->id, $targetId]);
        $this->app->make(AuditLog::class)->log('safety.unblock', $u->id, 'user', $targetId);
    }

    public function blockedList(int $userId): array
    {
        return $this->app->db()->fetchAll('SELECT b.blocked_id, b.created_at, p.display_name FROM blocks b LEFT JOIN profiles p ON p.user_id = b.blocked_id WHERE b.blocker_id = ? ORDER BY b.created_at DESC', [$userId]);
    }

    /** Resolve the owner of a report target and ensure the reporter could actually see it. */
    private function resolveTarget(User $u, string $type, int $id): int
    {
        $db = $this->app->db();
        switch ($type) {
            case 'user':
                $owner = $db->fetchColumn("SELECT id FROM users WHERE id = ? AND role = 'student'", [$id]);
                break;
            case 'listing':
                $owner = $db->fetchColumn('SELECT user_id FROM listings WHERE id = ?', [$id]);
                break;
            case 'message':
                $owner = $db->fetchColumn('SELECT m.sender_id FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE m.id = ? AND (c.user_a = ? OR c.user_b = ?)', [$id, $u->id, $u->id]);
                break;
            default:
                throw new HttpException(400);
        }
        if (!$owner) {
            throw new HttpException(404);
        }
        if ((int)$owner === $u->id) {
            throw ValidationException::single('reason', 'You cannot report yourself.');
        }
        return (int)$owner;
    }

    public function report(User $u, string $type, int $id, string $reason, string $details = '', bool $alsoBlock = false): int
    {
        if (!in_array($reason, Options::values('report_reason'), true)) {
            throw ValidationException::single('reason', 'Choose a reason.');
        }
        $details = mb_substr(trim(str_replace("\r", '', $details)), 0, 2000);
        if (!$this->app->make(RateLimiter::class)->hit('report:' . $u->id, 20, 86400)) {
            throw new HttpException(429, 'You have sent many reports today. Our team will review them shortly.');
        }
        $ownerId = $this->resolveTarget($u, $type, $id);
        $db = $this->app->db();
        if ($db->fetchColumn('SELECT 1 FROM reports WHERE reporter_id = ? AND target_type = ? AND target_id = ?', [$u->id, $type, $id])) {
            throw ValidationException::single('reason', 'You have already reported this. Our moderators will review it.');
        }
        $severity = in_array($reason, self::SERIOUS, true) ? 'serious' : 'normal';
        $reportId = $db->insert('reports', ['reporter_id' => $u->id, 'target_type' => $type, 'target_id' => $id, 'target_user_id' => $ownerId, 'reason' => $reason, 'details' => $details ?: null, 'severity' => $severity]);
        $this->app->make(AuditLog::class)->log('safety.report', $u->id, $type, $id, ['reason' => $reason, 'report_id' => $reportId]);

        // Listings with several independent open reports are hidden until a moderator reviews them.
        if ($type === 'listing') {
            $open = (int)$db->fetchColumn("SELECT COUNT(DISTINCT reporter_id) FROM reports WHERE target_type = 'listing' AND target_id = ? AND status IN ('open','escalated')", [$id]);
            if ($open >= (int)$this->app->settings()->get('rules.auto_hide_reports', 3)) {
                if ($db->update('listings', ['status' => 'hidden', 'moderation_note' => 'Hidden automatically after multiple reports — awaiting review.'], "id = ? AND status = 'published'", [$id]) > 0) {
                    $this->app->make(AuditLog::class)->log('listing.auto_hidden', null, 'listing', $id, ['open_reports' => $open]);
                }
            }
        }
        // Several serious reports against one person escalate all their open reports.
        $seriousAgainst = (int)$db->fetchColumn("SELECT COUNT(DISTINCT reporter_id) FROM reports WHERE target_user_id = ? AND severity = 'serious' AND status IN ('open','escalated')", [$ownerId]);
        if ($seriousAgainst >= 2) {
            $db->run("UPDATE reports SET status = 'escalated' WHERE target_user_id = ? AND status = 'open'", [$ownerId]);
        }
        $this->app->make(Notifier::class)->notifyStaff('reports', ($severity === 'serious' ? 'Serious report: ' : 'New report: ') . Options::label('report_reason', $reason), '/admin/reports/' . $reportId);
        if ($alsoBlock && $type !== 'listing') {
            $this->block($u, $ownerId);
        }
        return $reportId;
    }

    public function reports(string $status = 'open', int $limit = 50, int $offset = 0): array
    {
        $where = $status === 'all' ? '1=1' : ($status === 'open' ? "r.status IN ('open','escalated')" : 'r.status = ?');
        $params = in_array($status, ['all', 'open'], true) ? [] : [$status];
        return $this->app->db()->fetchAll("SELECT r.*, rp.display_name AS reporter_name, tp.display_name AS target_name, tu.status AS target_status
            FROM reports r LEFT JOIN profiles rp ON rp.user_id = r.reporter_id LEFT JOIN profiles tp ON tp.user_id = r.target_user_id LEFT JOIN users tu ON tu.id = r.target_user_id
            WHERE $where ORDER BY r.status = 'escalated' DESC, r.severity = 'serious' DESC, r.created_at ASC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
    }

    public function find(int $id): ?array
    {
        return $this->app->db()->fetch('SELECT r.*, rp.display_name AS reporter_name, ru.email AS reporter_email, tp.display_name AS target_name, tu.email AS target_email, tu.status AS target_status, tu.name AS target_legal_name
            FROM reports r LEFT JOIN profiles rp ON rp.user_id = r.reporter_id LEFT JOIN users ru ON ru.id = r.reporter_id LEFT JOIN profiles tp ON tp.user_id = r.target_user_id LEFT JOIN users tu ON tu.id = r.target_user_id WHERE r.id = ?', [$id]);
    }

    /** Content a moderator needs to judge a report (reported message with a little context, or the listing). */
    public function evidence(array $report): array
    {
        $db = $this->app->db();
        if ($report['target_type'] === 'message') {
            $m = $db->fetch('SELECT * FROM messages WHERE id = ?', [$report['target_id']]);
            if (!$m) {
                return [];
            }
            return ['messages' => $db->fetchAll('SELECT m.id, m.sender_id, m.body, m.created_at, m.hidden_at, p.display_name FROM messages m LEFT JOIN profiles p ON p.user_id = m.sender_id WHERE m.conversation_id = ? AND m.id BETWEEN ? AND ? ORDER BY m.id', [$m['conversation_id'], (int)$m['id'] - 5, (int)$m['id'] + 2]), 'focus' => (int)$m['id']];
        }
        if ($report['target_type'] === 'listing') {
            return ['listing' => $db->fetch('SELECT id, title, slug, status, description, rent, locality FROM listings WHERE id = ?', [$report['target_id']])];
        }
        return ['profile' => $db->fetch('SELECT display_name, bio, photo_path FROM profiles WHERE user_id = ?', [$report['target_id']])];
    }

    /**
     * @param string $action dismiss | warn | hide_content | restrict | suspend | escalate
     */
    public function decide(User $staff, int $reportId, string $action, string $note = ''): void
    {
        if (!$staff->can('reports')) {
            throw new HttpException(403);
        }
        $r = $this->find($reportId);
        if (!$r) {
            throw new HttpException(404);
        }
        if (in_array($action, ['restrict', 'suspend'], true) && !$staff->can('users')) {
            throw new HttpException(403, 'You need the "users" permission to restrict or suspend accounts.');
        }
        $note = mb_substr(trim($note), 0, 1000);
        $db = $this->app->db();
        $db->transaction(function (Database $db) use ($staff, $r, $action, $note): void {
            $status = match ($action) {
                'dismiss' => 'dismissed',
                'escalate' => 'escalated',
                'warn', 'hide_content', 'restrict', 'suspend' => 'actioned',
                default => throw ValidationException::single('action', 'Choose an action.'),
            };
            $db->update('reports', ['status' => $status, 'decided_by' => $staff->id, 'decided_at' => gmdate('Y-m-d H:i:s'), 'decision_note' => $note ?: null], 'id = ?', [$r['id']]);
            if ($action === 'hide_content') {
                if ($r['target_type'] === 'message') {
                    $db->run('UPDATE messages SET hidden_at = UTC_TIMESTAMP() WHERE id = ?', [$r['target_id']]);
                } elseif ($r['target_type'] === 'listing') {
                    $db->update('listings', ['status' => 'hidden', 'moderation_note' => $note ?: 'Hidden by a moderator after a report.'], 'id = ?', [$r['target_id']]);
                } else {
                    $db->update('profiles', ['discoverable' => 0, 'bio' => null], 'user_id = ?', [$r['target_id']]);
                }
            }
            if ($r['target_user_id'] && in_array($action, ['restrict', 'suspend'], true)) {
                $this->app->make(AdminService::class)->setStatus($staff, (int)$r['target_user_id'], $action === 'suspend' ? 'suspended' : 'restricted', $note ?: 'Action taken after a safety report.');
            }
            if ($r['target_user_id'] && $action === 'warn') {
                $this->app->make(Notifier::class)->notify((int)$r['target_user_id'], 'warning', 'A moderator reviewed a report about your account. Please read our safety guidelines.', '/safety');
            }
            // Resolve duplicates against the same target together.
            if (in_array($action, ['hide_content', 'restrict', 'suspend'], true)) {
                $db->run("UPDATE reports SET status = 'actioned', decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = COALESCE(decision_note, 'Resolved with linked report') WHERE target_type = ? AND target_id = ? AND status IN ('open','escalated')", [$staff->id, $r['target_type'], $r['target_id']]);
            }
        });
        $this->app->make(AuditLog::class)->log('report.' . $action, $staff->id, 'report', $reportId, ['target' => $r['target_type'] . ':' . $r['target_id']]);
        $this->app->make(Notifier::class)->notify((int)$r['reporter_id'], 'report', 'Thanks — a moderator has reviewed your report.', null);
    }
}
