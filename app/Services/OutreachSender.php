<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Services\Mail\MailException;
use BharatSeo\Services\Mail\MailMessage;

/**
 * Sends APPROVED outreach messages only, enforcing:
 * global pause, module switch, SMTP configuration, daily limits (global + per project),
 * per-domain cooldown, suppression list, duplicate-send protection and retry limits.
 */
final class OutreachSender
{
    private Database $db;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    /** Local-day boundaries (configured timezone) expressed in UTC. */
    private function todayUtcStart(): string
    {
        $tz = $this->app->timezone();
        $start = new \DateTimeImmutable('today', $tz);
        return $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function sentToday(?int $projectId = null): int
    {
        $sql = "SELECT COUNT(*) FROM outreach_messages WHERE status = 'sent' AND sent_at >= ?";
        $params = [$this->todayUtcStart()];
        if ($projectId !== null) {
            $sql .= ' AND project_id = ?';
            $params[] = $projectId;
        }
        return (int)$this->db->fetchColumn($sql, $params);
    }

    /** Messages stuck in "sending" (worker died mid-send) are failed for manual review — never auto-resent. */
    public function failStaleSending(): int
    {
        return $this->db->run(
            "UPDATE outreach_messages SET status = 'failed', last_error = 'Delivery outcome unknown (sending was interrupted). Verify with your mail provider before resending.'
             WHERE status = 'sending' AND updated_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE"
        )->rowCount();
    }

    /**
     * @return array{status:string, sent:int, failed:int, deferred:int, suppressed:int, skipped:int}
     */
    public function run(int $maxMessages = 20, int $maxSeconds = 45): array
    {
        $stats = ['status' => 'ok', 'sent' => 0, 'failed' => 0, 'deferred' => 0, 'suppressed' => 0, 'skipped' => 0];
        $settings = $this->app->settings();
        $settings->refresh();
        if ($settings->bool('queue.paused')) {
            return ['status' => 'paused'] + $stats;
        }
        if (!$settings->moduleEnabled('outreach')) {
            return ['status' => 'module_disabled'] + $stats;
        }
        $this->failStaleSending();
        $mailer = $this->app->make(MailerService::class);
        if (!$mailer->isConfigured()) {
            return ['status' => 'smtp_not_configured'] + $stats;
        }
        $dailyLimit = (int)$settings->get('outreach.daily_limit', 30);
        $projectLimit = (int)$settings->get('outreach.per_project_daily_limit', 15);
        $deadline = microtime(true) + $maxSeconds;

        $candidates = $this->db->fetchAll(
            "SELECT m.id, m.project_id FROM outreach_messages m JOIN projects p ON p.id = m.project_id AND p.status = 'active'
             WHERE m.status = 'approved' AND m.approved_revision = m.revision AND (m.next_attempt_at IS NULL OR m.next_attempt_at <= UTC_TIMESTAMP())
             ORDER BY m.approved_at ASC, m.id ASC LIMIT " . max(1, $maxMessages * 3)
        );
        foreach ($candidates as $c) {
            if ($stats['sent'] + $stats['failed'] >= $maxMessages || microtime(true) > $deadline) {
                break;
            }
            if ($this->sentToday() >= $dailyLimit) {
                $stats['status'] = 'daily_limit_reached';
                break;
            }
            if ($projectLimit > 0 && $this->sentToday((int)$c['project_id']) >= $projectLimit) {
                $stats['skipped']++;
                continue;
            }
            $r = $this->sendOne((int)$c['id']);
            $stats[$r] = ($stats[$r] ?? 0) + 1;
        }
        return $stats;
    }

    /** @return 'sent'|'failed'|'deferred'|'suppressed'|'skipped' */
    public function sendOne(int $id): string
    {
        // Claim atomically: only one worker can move approved → sending.
        $claimed = $this->db->run(
            "UPDATE outreach_messages SET status = 'sending', send_attempts = send_attempts + 1 WHERE id = ? AND status = 'approved' AND approved_revision = revision",
            [$id]
        )->rowCount();
        if ($claimed !== 1) {
            return 'skipped';
        }
        $m = $this->db->fetch(
            'SELECT m.*, p.domain AS project_domain, p.brand_name, p.contact_email AS project_contact, p.outreach_from_name, p.name AS project_name, pp.status AS prospect_status, pp.domain AS prospect_domain, pp.prospect_domain_id
             FROM outreach_messages m JOIN projects p ON p.id = m.project_id JOIN prospect_pages pp ON pp.id = m.prospect_page_id WHERE m.id = ?',
            [$id]
        );
        $settings = $this->app->settings();
        $email = strtolower(trim((string)$m['recipient_email']));

        // Suppression / unsubscribe
        if ($email === '' || $this->app->make(SuppressionService::class)->isSuppressed($email, (int)$m['project_id'])) {
            $this->db->update('outreach_messages', ['status' => 'suppressed', 'last_error' => 'Recipient is suppressed or missing.'], 'id = ?', [$id]);
            $this->audit('outreach.suppressed', $m, []);
            return 'suppressed';
        }
        // Prospect must still be approved / contacted.
        if (!in_array($m['prospect_status'], ['approved', 'outreach_sent'], true)) {
            $this->db->update('outreach_messages', ['status' => 'cancelled', 'last_error' => 'Prospect is no longer approved (' . $m['prospect_status'] . ').'], 'id = ?', [$id]);
            $this->audit('outreach.cancelled_prospect_status', $m, ['prospect_status' => $m['prospect_status']]);
            return 'skipped';
        }
        // Duplicate-send protection: same recipient, same project, same message kind already sent.
        $dup = $this->db->fetchColumn(
            "SELECT id FROM outreach_messages WHERE id <> ? AND project_id = ? AND recipient_email = ? AND message_type = ? AND follow_up_number = ? AND status IN ('sent','sending') LIMIT 1",
            [$id, $m['project_id'], $email, $m['message_type'], $m['follow_up_number']]
        );
        if ($dup) {
            $this->db->update('outreach_messages', ['status' => 'cancelled', 'last_error' => 'Duplicate: message #' . $dup . ' was already sent to this recipient.'], 'id = ?', [$id]);
            $this->audit('outreach.duplicate_blocked', $m, ['duplicate_of' => (int)$dup]);
            return 'skipped';
        }
        // Follow-ups only if the parent was sent and got no reply.
        if ($m['message_type'] === 'follow_up') {
            $parent = $this->db->fetch('SELECT status, reply_status FROM outreach_messages WHERE id = ?', [$m['parent_message_id']]);
            if (!$parent || $parent['status'] !== 'sent' || $parent['reply_status'] !== 'none') {
                $this->db->update('outreach_messages', ['status' => 'cancelled', 'last_error' => 'Follow-up no longer appropriate (reply received or original not sent).'], 'id = ?', [$id]);
                return 'skipped';
            }
        }
        // Per-domain cooldown for initial messages (follow-ups are part of the same conversation).
        if ($m['message_type'] === 'initial') {
            $cooldownDays = (int)$settings->get('outreach.domain_cooldown_days', 14);
            $recipientDomain = substr((string)strrchr($email, '@'), 1);
            $last = $this->db->fetchColumn(
                "SELECT MAX(m2.sent_at) FROM outreach_messages m2 JOIN prospect_pages pp2 ON pp2.id = m2.prospect_page_id
                 WHERE m2.project_id = ? AND m2.status = 'sent' AND m2.message_type = 'initial' AND m2.id <> ?
                   AND (pp2.domain = ? OR m2.recipient_email LIKE ?) AND m2.sent_at > UTC_TIMESTAMP() - INTERVAL ? DAY",
                [$m['project_id'], $id, $m['prospect_domain'], '%@' . $recipientDomain, $cooldownDays]
            );
            if ($last) {
                $next = gmdate('Y-m-d H:i:s', strtotime($last . ' UTC') + $cooldownDays * 86400);
                $this->db->update('outreach_messages', ['status' => 'approved', 'next_attempt_at' => $next, 'send_attempts' => max(0, (int)$m['send_attempts'] - 1), 'last_error' => 'Deferred: per-domain cooldown until ' . $next . ' UTC.'], 'id = ?', [$id]);
                return 'deferred';
            }
        }

        $mailer = $this->app->make(MailerService::class);
        $unsubUrl = absolute_url('/unsubscribe/' . $m['unsubscribe_token']);
        $address = trim((string)$settings->get('outreach.physical_address', ''));
        $footer = "\n\n--\nYou are receiving this one-off message because your contact details are publicly listed on {$m['prospect_domain']}. "
            . "If you prefer not to hear from us again, reply \"unsubscribe\" or visit: $unsubUrl" . ($address !== '' ? "\n" . $address : '');
        try {
            $replyTo = filter_var((string)$m['project_contact'], FILTER_VALIDATE_EMAIL) ? (string)$m['project_contact'] : null;
            $fromName = trim((string)($m['outreach_from_name'] ?: $mailer->fromName()));
            $msg = (new MailMessage($mailer->fromEmail(), $fromName, $email, $m['recipient_name'] ?: null, (string)$m['subject'], (string)$m['body'] . $footer, $replyTo))
                ->withHeader('List-Unsubscribe', '<' . $unsubUrl . '>' . ($replyTo ? ', <mailto:' . $replyTo . '?subject=unsubscribe>' : ''))
                ->withHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click')
                ->withHeader('X-BharatSEO-Message', (string)$id);
            $messageId = $mailer->transport()->send($msg);
        } catch (MailException $e) {
            $attempts = (int)$m['send_attempts'];
            $max = (int)$settings->get('outreach.max_send_attempts', 3);
            $error = mb_substr(\BharatSeo\Core\Logger::redactString($e->getMessage()), 0, 500);
            if ($e->isPermanent() || $attempts >= $max) {
                $this->db->update('outreach_messages', ['status' => 'failed', 'last_error' => $error], 'id = ?', [$id]);
                $this->app->make(NotificationService::class)->notifyProject((int)$m['project_id'], 'outreach_failed', 'Outreach email failed', $error, url('/outreach/messages/' . $id));
                $this->audit('outreach.send_failed', $m, ['error' => $error, 'attempts' => $attempts, 'final' => true]);
                return 'failed';
            }
            $next = gmdate('Y-m-d H:i:s', time() + QueueService::backoffSeconds($attempts) * 5);
            $this->db->update('outreach_messages', ['status' => 'approved', 'last_error' => $error, 'next_attempt_at' => $next], 'id = ?', [$id]);
            $this->audit('outreach.send_failed', $m, ['error' => $error, 'attempts' => $attempts, 'retry_at' => $next]);
            return 'deferred';
        }
        $this->db->transaction(function (Database $db) use ($id, $m, $messageId): void {
            $db->update('outreach_messages', ['status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s'), 'smtp_message_id' => mb_substr($messageId, 0, 255), 'last_error' => null], 'id = ?', [$id]);
            $db->update('prospect_domains', ['last_contacted_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$m['prospect_domain_id']]);
        });
        if ($m['prospect_status'] === 'approved') {
            try {
                $this->app->make(ProspectService::class)->transition((int)$m['prospect_page_id'], 'outreach_sent', null, 'Outreach email sent (message #' . $id . ')');
            } catch (\Throwable $e) {
                $this->app->logger()->warning('Prospect transition after send failed', ['error' => $e->getMessage()]);
            }
        }
        $this->audit('outreach.sent', $m, ['message_id' => $messageId]);
        return 'sent';
    }

    private function audit(string $action, array $m, array $details): void
    {
        $this->app->make(AuditLogger::class)->log($action, null, (int)$m['project_id'], $details + ['recipient_hash' => substr(hash('sha256', (string)$m['recipient_email']), 0, 16)], 'outreach_message', null, (int)$m['id']);
    }
}
