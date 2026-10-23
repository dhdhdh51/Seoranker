<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;

/** Email suppression list (unsubscribes, bounces, complaints, manual). */
final class SuppressionService
{
    private Database $db;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    public function isSuppressed(string $email, ?int $projectId = null): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        $domainWildcard = '*@' . substr((string)strrchr($email, '@'), 1);
        $keys = ['global'];
        if ($projectId) {
            $keys[] = 'p:' . $projectId;
        }
        return (bool)$this->db->fetchColumn(
            'SELECT 1 FROM email_suppressions WHERE email IN (?, ?) AND scope_key IN (' . Database::in($keys) . ') LIMIT 1',
            array_merge([$email, $domainWildcard], $keys)
        );
    }

    /** $email may be "*@domain.com" to suppress a whole domain. */
    public function add(string $email, ?int $projectId, string $reason, ?string $note, ?int $userId): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) && !preg_match('/^\*@[a-z0-9.\-]+\.[a-z]{2,}$/', $email)) {
            return false;
        }
        $n = $this->db->run(
            'INSERT IGNORE INTO email_suppressions (email, scope_key, project_id, reason, note, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$email, $projectId ? 'p:' . $projectId : 'global', $projectId, $reason, $note !== null ? mb_substr($note, 0, 255) : null, $userId]
        )->rowCount();
        // Stop anything queued for this address.
        $this->db->run("UPDATE outreach_messages SET status = 'suppressed', last_error = 'Recipient suppressed' WHERE recipient_email = ? AND status IN ('draft','needs_review','approved')" . ($projectId ? ' AND project_id = ' . (int)$projectId : ''), [$email]);
        $this->app->make(AuditLogger::class)->log('suppression.added', $userId, $projectId, ['email_hash' => substr(hash('sha256', $email), 0, 16), 'reason' => $reason], 'suppression');
        return $n > 0;
    }

    public function remove(int $id, int $userId): void
    {
        $row = $this->db->fetch('SELECT * FROM email_suppressions WHERE id = ?', [$id]);
        if (!$row) {
            return;
        }
        if ($row['reason'] === 'unsubscribe') {
            throw new \BharatSeo\Core\ValidationException(['id' => 'Unsubscribe requests cannot be removed.']);
        }
        $this->db->run('DELETE FROM email_suppressions WHERE id = ?', [$id]);
        $this->app->make(AuditLogger::class)->log('suppression.removed', $userId, $row['project_id'] ? (int)$row['project_id'] : null, ['reason' => $row['reason']], 'suppression', null, $id);
    }

    public function list(?array $projectIds, int $limit = 500): array
    {
        $sql = 'SELECT s.*, p.name AS project_name FROM email_suppressions s LEFT JOIN projects p ON p.id = s.project_id';
        $params = [];
        if ($projectIds !== null) {
            $sql .= ' WHERE s.project_id IN (' . Database::in($projectIds) . ')';
            $params = $projectIds;
        }
        return $this->db->fetchAll($sql . ' ORDER BY s.id DESC LIMIT ' . max(1, $limit), $params);
    }

    /** Public unsubscribe by message token. */
    public function unsubscribeByToken(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $m = $this->db->fetch('SELECT id, recipient_email, project_id FROM outreach_messages WHERE unsubscribe_token = ?', [$token]);
        if (!$m || !$m['recipient_email']) {
            return null;
        }
        $this->add((string)$m['recipient_email'], null, 'unsubscribe', 'Unsubscribe link (message #' . $m['id'] . ')', null);
        return (string)$m['recipient_email'];
    }
}
