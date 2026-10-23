<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Core\HttpException;
use BharatSeo\Core\ValidationException;
use BharatSeo\Security\Gate;
use BharatSeo\Security\User;

/**
 * Approval workflow for prospects and outreach messages.
 * Project policy decides who must approve outreach: manager, client, or both.
 * Approvals are bound to a message revision; editing invalidates them.
 */
final class ApprovalService
{
    private Database $db;
    private Gate $gate;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
        $this->gate = $app->make(Gate::class);
    }

    public static function requiredParties(string $policy): array
    {
        return match ($policy) {
            'client' => ['client'],
            'manager_and_client' => ['manager', 'client'],
            default => ['manager'],
        };
    }

    public function requestMessageApproval(User $user, array $message): void
    {
        $parties = self::requiredParties((string)$message['approval_policy']);
        $this->db->transaction(function (Database $db) use ($user, $message, $parties): void {
            $locked = $db->fetch('SELECT status, revision FROM outreach_messages WHERE id = ? FOR UPDATE', [$message['id']]);
            if (!in_array($locked['status'], ['draft', 'failed'], true)) {
                throw ValidationException::single('status', 'Message is not a draft.');
            }
            foreach ($parties as $party) {
                $db->run(
                    'INSERT INTO approval_requests (project_id, client_id, entity_type, entity_id, entity_revision, required_party, status, pending_key, requested_by)
                     VALUES (?, ?, \'outreach_message\', ?, ?, ?, \'pending\', ?, ?)
                     ON DUPLICATE KEY UPDATE entity_revision = VALUES(entity_revision)',
                    [$message['project_id'], $message['client_id'], $message['id'], $locked['revision'], $party, 'outreach_message:' . $message['id'] . ':' . $party, $user->id]
                );
            }
            $db->update('outreach_messages', ['status' => 'needs_review'], 'id = ?', [$message['id']]);
        });
        $this->app->make(AuditLogger::class)->log('approval.requested', $user->id, (int)$message['project_id'], ['parties' => $parties], 'outreach_message', null, (int)$message['id']);
        $this->app->make(NotificationService::class)->notifyProject((int)$message['project_id'], 'approval_requested', 'Outreach awaiting approval', 'To ' . $message['recipient_email'] . ': ' . $message['subject'], url('/approvals'), in_array('client', $parties, true));
        if (in_array('manager', $parties, true)) {
            $this->app->make(NotificationService::class)->notifyAdmins('approval_requested', 'Outreach awaiting approval', $message['subject'], url('/approvals'));
        }
    }

    public function cancelPending(string $entityType, int $entityId, string $note): void
    {
        $this->db->run(
            "UPDATE approval_requests SET status = 'cancelled', pending_key = NULL, decided_at = UTC_TIMESTAMP(), decision_note = ? WHERE entity_type = ? AND entity_id = ? AND status = 'pending'",
            [$note, $entityType, $entityId]
        );
    }

    /** Can $user act as $party on this project? */
    public function canDecide(User $user, int $projectId, string $party): bool
    {
        if ($party === 'client') {
            return $this->gate->can($user, Gate::APPROVE_CLIENT, $projectId);
        }
        return $user->isSuperAdmin() || $this->gate->can($user, Gate::MANAGE, $projectId);
    }

    /** Pending outreach approvals the user can decide on. */
    public function pendingMessages(User $user): array
    {
        [$scope, $params] = $this->gate->scope($user, 'a.project_id');
        $rows = $this->db->fetchAll(
            "SELECT a.*, m.subject, m.body, m.recipient_email, m.revision, m.generator, m.generator_note, m.message_type, p.name AS project_name, pp.normalized_url AS prospect_url, pp.domain AS prospect_domain, pp.quality_score
             FROM approval_requests a JOIN outreach_messages m ON m.id = a.entity_id JOIN projects p ON p.id = a.project_id JOIN prospect_pages pp ON pp.id = m.prospect_page_id
             WHERE a.entity_type = 'outreach_message' AND a.status = 'pending'$scope ORDER BY a.id ASC LIMIT 200",
            $params
        );
        return array_values(array_filter($rows, fn($r) => $this->canDecide($user, (int)$r['project_id'], $r['required_party'])));
    }

    /** Prospects awaiting review that the user may approve (staff only). */
    public function pendingProspects(User $user, ?int $projectId = null): array
    {
        if (!$user->isStaff()) {
            return [];
        }
        [$scope, $params] = $this->gate->scope($user, 'pp.project_id');
        $sql = "SELECT pp.*, p.name AS project_name FROM prospect_pages pp JOIN projects p ON p.id = pp.project_id WHERE pp.status = 'needs_review'$scope";
        if ($projectId) {
            $sql .= ' AND pp.project_id = ?';
            $params[] = $projectId;
        }
        $rows = $this->db->fetchAll($sql . ' ORDER BY pp.manual_hold ASC, pp.quality_score DESC LIMIT 200', $params);
        return array_values(array_filter($rows, fn($r) => $this->canDecide($user, (int)$r['project_id'], 'manager')));
    }

    public function decideMessage(User $user, int $approvalId, bool $approve, ?string $note): string
    {
        $outcome = $this->db->transaction(function (Database $db) use ($user, $approvalId, $approve, $note): array {
            $a = $db->fetch("SELECT * FROM approval_requests WHERE id = ? AND entity_type = 'outreach_message' FOR UPDATE", [$approvalId]);
            if (!$a || !$this->gate->can($user, Gate::VIEW, (int)$a['project_id'])) {
                throw new HttpException(404);
            }
            if (!$this->canDecide($user, (int)$a['project_id'], $a['required_party'])) {
                throw new HttpException(403);
            }
            if ($a['status'] !== 'pending') {
                throw ValidationException::single('approval', 'This approval request was already decided or cancelled.');
            }
            $m = $db->fetch('SELECT id, revision, status, project_id, created_by FROM outreach_messages WHERE id = ? FOR UPDATE', [$a['entity_id']]);
            if (!$m || $m['status'] !== 'needs_review' || (int)$m['revision'] !== (int)$a['entity_revision']) {
                $db->run("UPDATE approval_requests SET status = 'cancelled', pending_key = NULL, decided_at = UTC_TIMESTAMP(), decision_note = 'Stale: message changed' WHERE id = ?", [$approvalId]);
                throw ValidationException::single('approval', 'The message changed after this approval was requested. Review the latest revision.');
            }
            if (!$approve && mb_strlen(trim((string)$note)) < 3) {
                throw ValidationException::single('note', 'Please give a reason for the rejection.');
            }
            $db->run(
                'UPDATE approval_requests SET status = ?, pending_key = NULL, decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = ? WHERE id = ?',
                [$approve ? 'approved' : 'rejected', $user->id, $note !== null ? mb_substr($note, 0, 1000) : null, $approvalId]
            );
            if (!$approve) {
                $db->run("UPDATE approval_requests SET status = 'cancelled', pending_key = NULL, decided_at = UTC_TIMESTAMP(), decision_note = 'Another approver rejected' WHERE entity_type = 'outreach_message' AND entity_id = ? AND status = 'pending'", [$m['id']]);
                $db->update('outreach_messages', ['status' => 'draft', 'last_error' => mb_substr('Rejected: ' . (string)$note, 0, 500)], 'id = ?', [$m['id']]);
                return ['result' => 'rejected', 'message' => $m];
            }
            $remaining = (int)$db->fetchColumn("SELECT COUNT(*) FROM approval_requests WHERE entity_type = 'outreach_message' AND entity_id = ? AND status = 'pending'", [$m['id']]);
            if ($remaining === 0) {
                $db->update('outreach_messages', ['status' => 'approved', 'approved_revision' => (int)$m['revision'], 'approved_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null, 'next_attempt_at' => null, 'send_attempts' => 0], 'id = ?', [$m['id']]);
                return ['result' => 'approved', 'message' => $m];
            }
            return ['result' => 'partially_approved', 'message' => $m];
        });
        $m = $outcome['message'];
        $this->app->make(AuditLogger::class)->log('approval.' . $outcome['result'], $user->id, (int)$m['project_id'], ['approval_id' => $approvalId, 'note' => $note, 'revision' => (int)$m['revision']], 'outreach_message', null, (int)$m['id']);
        if ($m['created_by']) {
            $this->app->make(NotificationService::class)->notify((int)$m['created_by'], 'approval_decided', 'Outreach ' . str_replace('_', ' ', $outcome['result']), $note, url('/outreach/messages/' . $m['id']), (int)$m['project_id']);
        }
        return $outcome['result'];
    }

    /** Approve or reject a prospect (staff). Records an approval_request row for traceability. */
    public function decideProspect(User $user, int $prospectId, bool $approve, ?string $note): void
    {
        $p = $this->app->make(ProspectService::class)->find($user, $prospectId);
        if (!$this->canDecide($user, (int)$p['project_id'], 'manager')) {
            throw new HttpException(403);
        }
        if (!$approve && mb_strlen(trim((string)$note)) < 3) {
            throw ValidationException::single('note', 'Please give a reason for the rejection.');
        }
        $this->db->transaction(function (Database $db) use ($user, $p, $approve, $note, $prospectId): void {
            $this->app->make(ProspectService::class)->transition($prospectId, $approve ? 'approved' : 'rejected', $user->id, $note);
            $db->insert('approval_requests', [
                'project_id' => (int)$p['project_id'],
                'client_id' => (int)$p['client_id'],
                'entity_type' => 'prospect',
                'entity_id' => $prospectId,
                'required_party' => 'manager',
                'status' => $approve ? 'approved' : 'rejected',
                'requested_by' => $user->id,
                'decided_by' => $user->id,
                'decided_at' => gmdate('Y-m-d H:i:s'),
                'decision_note' => $note !== null ? mb_substr($note, 0, 1000) : null,
            ]);
        });
        $this->app->make(AuditLogger::class)->log($approve ? 'approval.prospect_approved' : 'approval.prospect_rejected', $user->id, (int)$p['project_id'], ['note' => $note, 'score' => $p['quality_score']], 'prospect', null, $prospectId);
        if ($approve) {
            $this->app->make(OutreachService::class)->autoDraftForProspect($prospectId);
        }
    }

    public function history(User $user, int $limit = 100): array
    {
        [$scope, $params] = $this->gate->scope($user, 'a.project_id');
        return $this->db->fetchAll(
            "SELECT a.*, p.name AS project_name, u.name AS decided_by_name FROM approval_requests a JOIN projects p ON p.id = a.project_id LEFT JOIN users u ON u.id = a.decided_by
             WHERE a.status <> 'pending'$scope ORDER BY a.decided_at DESC, a.id DESC LIMIT " . max(1, $limit),
            $params
        );
    }
}
