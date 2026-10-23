<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Core\HttpException;
use BharatSeo\Core\ValidationException;
use BharatSeo\Security\Gate;
use BharatSeo\Security\User;
use BharatSeo\Services\Outreach\ContentGuard;
use BharatSeo\Services\Outreach\LocalModelClient;
use BharatSeo\Services\Outreach\OutreachWriter;
use BharatSeo\Support\Labels;
use BharatSeo\Support\Validator;

/**
 * Campaigns, drafts, revisions, approval submission, follow-ups and reply tracking.
 * Sending is done exclusively by OutreachSender for approved messages.
 */
final class OutreachService
{
    /** prospect category => default campaign/template type */
    public const CATEGORY_TEMPLATE = [
        'guest_post' => 'guest_post',
        'expert_contribution' => 'expert_contribution',
        'resource_page' => 'resource_page',
        'broken_link' => 'broken_link',
        'unlinked_mention' => 'unlinked_mention',
        'partner_page' => 'partner_page',
        'industry_association' => 'general',
        'community_profile' => 'general',
        'business_directory' => 'general',
        'local_citation' => 'general',
        'other' => 'general',
    ];

    private Database $db;
    private Gate $gate;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
        $this->gate = $app->make(Gate::class);
    }

    public function assertModule(): void
    {
        if (!$this->app->settings()->moduleEnabled('outreach')) {
            throw new HttpException(403, 'The outreach module is disabled by the administrator.');
        }
    }

    // ------------------------------------------------------------------ campaigns

    public function campaigns(User $user, ?int $projectId = null): array
    {
        [$scope, $params] = $this->gate->scope($user, 'c.project_id');
        $sql = "SELECT c.*, p.name AS project_name,
                    (SELECT COUNT(*) FROM outreach_messages m WHERE m.campaign_id = c.id) AS message_count,
                    (SELECT COUNT(*) FROM outreach_messages m WHERE m.campaign_id = c.id AND m.status = 'sent') AS sent_count,
                    (SELECT COUNT(*) FROM outreach_messages m WHERE m.campaign_id = c.id AND m.reply_status IN ('replied','positive')) AS reply_count
                FROM outreach_campaigns c JOIN projects p ON p.id = c.project_id WHERE 1=1$scope";
        if ($projectId) {
            $sql .= ' AND c.project_id = ?';
            $params[] = $projectId;
        }
        return $this->db->fetchAll($sql . ' ORDER BY c.status = \'active\' DESC, c.id DESC', $params);
    }

    public function campaign(User $user, int $id): array
    {
        [$scope, $params] = $this->gate->scope($user, 'c.project_id');
        $c = $this->db->fetch("SELECT c.*, p.name AS project_name FROM outreach_campaigns c JOIN projects p ON p.id = c.project_id WHERE c.id = ?$scope", array_merge([$id], $params));
        if (!$c) {
            throw new HttpException(404);
        }
        return $c;
    }

    public function saveCampaign(User $user, ?int $id, array $input): int
    {
        $this->assertModule();
        $d = Validator::make($input)
            ->string('name', 'Campaign name', true, 190, 2)
            ->in('type', 'Type', array_keys(Labels::CAMPAIGN_TYPES))
            ->in('status', 'Status', ['draft', 'active', 'paused', 'completed'], true, 'draft')
            ->int('follow_up_days', 'Follow-up after (days)', true, 2, 60)
            ->int('max_follow_ups', 'Max follow-ups', true, 0, 2)
            ->bool('auto_drafts')
            ->validated();
        if ($id === null) {
            $projectId = (int)($input['project_id'] ?? 0);
            $project = $this->app->make(ProjectService::class)->find($user, $projectId, Gate::MANAGE);
        } else {
            $existing = $this->campaign($user, $id);
            $projectId = (int)$existing['project_id'];
            $this->gate->authorizeProject($user, $projectId, Gate::MANAGE);
            $project = ['id' => $projectId, 'client_id' => $existing['client_id']];
        }
        $row = [
            'name' => $d['name'], 'type' => $d['type'], 'status' => $d['status'],
            'follow_up_days' => $d['follow_up_days'], 'max_follow_ups' => $d['max_follow_ups'], 'auto_drafts' => (int)$d['auto_drafts'],
        ];
        try {
            if ($id === null) {
                $id = $this->db->insert('outreach_campaigns', $row + ['project_id' => $projectId, 'client_id' => (int)$project['client_id'], 'created_by' => $user->id]);
            } else {
                $this->db->update('outreach_campaigns', $row, 'id = ?', [$id]);
            }
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw ValidationException::single('name', 'A campaign with this name already exists in the project.');
            }
            throw $e;
        }
        $this->app->make(AuditLogger::class)->log('outreach.campaign_saved', $user->id, $projectId, $row, 'campaign', null, $id);
        return $id;
    }

    // ------------------------------------------------------------------ drafts

    public function message(User $user, int $id): array
    {
        [$scope, $params] = $this->gate->scope($user, 'm.project_id');
        $m = $this->db->fetch(
            "SELECT m.*, c.name AS campaign_name, c.type AS campaign_type, p.name AS project_name, p.approval_policy, pp.normalized_url AS prospect_url, pp.title AS prospect_title, pp.domain AS prospect_domain, pp.status AS prospect_status
             FROM outreach_messages m JOIN outreach_campaigns c ON c.id = m.campaign_id JOIN projects p ON p.id = m.project_id JOIN prospect_pages pp ON pp.id = m.prospect_page_id
             WHERE m.id = ?$scope",
            array_merge([$id], $params)
        );
        if (!$m) {
            throw new HttpException(404);
        }
        return $m;
    }

    public function messages(User $user, array $f, int $limit = 200): array
    {
        [$scope, $params] = $this->gate->scope($user, 'm.project_id');
        $sql = "SELECT m.*, c.name AS campaign_name, pp.domain AS prospect_domain, pp.normalized_url AS prospect_url, p.name AS project_name
                FROM outreach_messages m JOIN outreach_campaigns c ON c.id = m.campaign_id JOIN prospect_pages pp ON pp.id = m.prospect_page_id JOIN projects p ON p.id = m.project_id WHERE 1=1$scope";
        if (!empty($f['campaign_id'])) {
            $sql .= ' AND m.campaign_id = ?';
            $params[] = (int)$f['campaign_id'];
        }
        if (!empty($f['project_id'])) {
            $sql .= ' AND m.project_id = ?';
            $params[] = (int)$f['project_id'];
        }
        if (!empty($f['status']) && isset(Labels::MESSAGE_STATUSES[$f['status']])) {
            $sql .= ' AND m.status = ?';
            $params[] = $f['status'];
        }
        return $this->db->fetchAll($sql . ' ORDER BY m.id DESC LIMIT ' . max(1, $limit), $params);
    }

    public static function dedupeHash(int $campaignId, int $prospectId, string $type, int $followUp): string
    {
        return hash('sha256', "$campaignId|$prospectId|$type|$followUp");
    }

    /**
     * Create (or return the existing) initial draft for a prospect in a campaign.
     * Idempotent thanks to the dedupe hash.
     */
    public function createDraft(?User $user, int $campaignId, int $prospectId, bool $useLocalModel = false): int
    {
        $this->assertModule();
        $campaign = $user ? $this->campaign($user, $campaignId) : $this->db->fetch('SELECT * FROM outreach_campaigns WHERE id = ?', [$campaignId]);
        if (!$campaign) {
            throw new HttpException(404);
        }
        if ($user) {
            $this->gate->authorizeProject($user, (int)$campaign['project_id'], Gate::MANAGE);
        }
        $prospect = $this->db->fetch('SELECT * FROM prospect_pages WHERE id = ? AND project_id = ?', [$prospectId, $campaign['project_id']]);
        if (!$prospect) {
            throw ValidationException::single('prospect_id', 'Prospect does not belong to this campaign\'s project.');
        }
        if (!in_array($prospect['status'], ['approved', 'outreach_sent'], true)) {
            throw ValidationException::single('prospect_id', 'Only approved prospects can receive outreach drafts.');
        }
        if (in_array($prospect['category'], ['business_directory', 'local_citation', 'community_profile'], true) && $prospect['contact_email'] === null) {
            throw ValidationException::single('prospect_id', 'Directory/citation/profile opportunities are submitted manually on the site\'s official submission page, not by email.');
        }
        $hash = self::dedupeHash($campaignId, $prospectId, 'initial', 0);
        $existing = $this->db->fetchColumn('SELECT id FROM outreach_messages WHERE dedupe_hash = ?', [$hash]);
        if ($existing) {
            return (int)$existing;
        }
        $draft = $this->generate((string)$campaign['type'], $campaign, $prospect, [], $useLocalModel);
        try {
            $id = $this->db->transaction(function (Database $db) use ($campaign, $prospect, $draft, $hash, $user): int {
                $id = $db->insert('outreach_messages', [
                    'campaign_id' => (int)$campaign['id'],
                    'project_id' => (int)$campaign['project_id'],
                    'client_id' => (int)$campaign['client_id'],
                    'prospect_page_id' => (int)$prospect['id'],
                    'message_type' => 'initial',
                    'follow_up_number' => 0,
                    'recipient_email' => $prospect['contact_email'],
                    'recipient_name' => $prospect['contact_name'],
                    'subject' => $draft['subject'],
                    'body' => $draft['body'],
                    'subject_alternatives' => json_encode($draft['alternatives'], JSON_UNESCAPED_UNICODE),
                    'generator' => $draft['generator'],
                    'generator_note' => $draft['note'] !== null ? mb_substr($draft['note'], 0, 255) : null,
                    'status' => 'draft',
                    'unsubscribe_token' => bin2hex(random_bytes(32)),
                    'dedupe_hash' => $hash,
                    'created_by' => $user?->id,
                ]);
                $db->insert('outreach_message_revisions', ['message_id' => $id, 'revision' => 1, 'subject' => $draft['subject'], 'body' => $draft['body'], 'edited_by' => $user?->id]);
                return $id;
            });
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                return (int)$this->db->fetchColumn('SELECT id FROM outreach_messages WHERE dedupe_hash = ?', [$hash]);
            }
            throw $e;
        }
        $this->app->make(AuditLogger::class)->log('outreach.draft_created', $user?->id, (int)$campaign['project_id'], ['generator' => $draft['generator'], 'prospect_id' => $prospectId], 'outreach_message', null, $id);
        return $id;
    }

    /**
     * Produce a draft using the template writer, optionally refined by the local model.
     * Falls back to the template when the model is unavailable or its output fails validation.
     */
    public function generate(string $type, array $campaign, array $prospect, array $extra = [], bool $useLocalModel = false): array
    {
        $project = $this->db->fetch('SELECT * FROM projects WHERE id = ?', [$campaign['project_id']]);
        $projects = $this->app->make(ProjectService::class);
        $target = $prospect['proposed_target_page_id'] ? $this->db->fetch('SELECT * FROM target_pages WHERE id = ?', [$prospect['proposed_target_page_id']]) : null;
        $ctx = [
            'prospect' => $prospect,
            'project' => $project,
            'target' => $target,
            'keywords' => $projects->keywords((int)$project['id'], 'keyword'),
            'topics' => $projects->keywords((int)$project['id'], 'topic'),
            'sender' => $project['outreach_from_name'] ?: (string)$this->app->settings()->get('smtp.from_name'),
        ] + $extra;
        $draft = (new OutreachWriter())->draft($type, $ctx);
        if ($useLocalModel) {
            $model = $this->app->make(LocalModelClient::class);
            if (!$model->isAvailable()) {
                $draft['note'] = trim(($draft['note'] ?? '') . ' Local model not configured — template used.');
            } else {
                try {
                    $facts = [
                        'recipient_name' => $prospect['contact_name'] ?: null,
                        'site_name' => OutreachWriter::siteName($prospect),
                        'page_title' => $prospect['title'],
                        'page_url' => $prospect['normalized_url'],
                        'opportunity_type' => $type,
                        'evidence' => $prospect['evidence'],
                        'our_brand' => $project['brand_name'] ?: $project['domain'],
                        'our_niche' => $project['niche'],
                        'our_target_page' => $target['url'] ?? $project['website_url'],
                        'sender_name' => $ctx['sender'],
                    ];
                    $out = $model->rewrite($facts, $draft);
                    $problems = ContentGuard::problems($out['subject'], $out['body'], $prospect['contact_name']);
                    if ($problems) {
                        $draft['note'] = 'Local model output rejected (' . implode(' ', $problems) . ') — template used.';
                    } else {
                        $draft = ['subject' => $out['subject'], 'alternatives' => array_merge([$draft['subject']], $draft['alternatives']), 'body' => $out['body'], 'generator' => 'local_model', 'note' => $draft['note']];
                    }
                } catch (\Throwable $e) {
                    $draft['note'] = trim(($draft['note'] ?? '') . ' Local model failed (' . mb_substr($e->getMessage(), 0, 120) . ') — template used.');
                }
            }
        }
        $draft['note'] = $draft['note'] !== null ? trim($draft['note']) : null;
        return $draft;
    }

    public function revisions(int $messageId): array
    {
        return $this->db->fetchAll('SELECT r.*, u.name AS editor FROM outreach_message_revisions r LEFT JOIN users u ON u.id = r.edited_by WHERE r.message_id = ? ORDER BY r.revision DESC', [$messageId]);
    }

    /** Save an edit as a new revision. Any existing approval is invalidated. */
    public function updateDraft(User $user, int $id, array $input): void
    {
        $m = $this->message($user, $id);
        $this->gate->authorizeProject($user, (int)$m['project_id'], Gate::MANAGE);
        if (!in_array($m['status'], ['draft', 'needs_review', 'approved', 'failed'], true)) {
            throw ValidationException::single('status', 'Sent, sending, cancelled or suppressed messages cannot be edited.');
        }
        $d = Validator::make($input)
            ->string('subject', 'Subject', true, 200, 3)
            ->text('body', 'Message body', true, 8000)
            ->email('recipient_email', 'Recipient email', false)
            ->string('recipient_name', 'Recipient name', false, 160)
            ->validated();
        $problems = ContentGuard::problems($d['subject'], (string)$d['body'], $d['recipient_name']);
        if ($problems) {
            throw ValidationException::single('body', implode(' ', $problems));
        }
        $this->db->transaction(function (Database $db) use ($m, $d, $user, $id): void {
            $locked = $db->fetch('SELECT revision, status FROM outreach_messages WHERE id = ? FOR UPDATE', [$id]);
            if (!in_array($locked['status'], ['draft', 'needs_review', 'approved', 'failed'], true)) {
                throw ValidationException::single('status', 'Message can no longer be edited.');
            }
            $rev = (int)$locked['revision'] + 1;
            $db->update('outreach_messages', [
                'subject' => $d['subject'],
                'body' => $d['body'],
                'recipient_email' => $d['recipient_email'],
                'recipient_name' => $d['recipient_name'],
                'revision' => $rev,
                'status' => 'draft',
                'generator' => $m['generator'] === 'template' || $m['generator'] === 'local_model' ? $m['generator'] : 'manual',
                'approved_revision' => null,
                'approved_at' => null,
                'last_error' => null,
                'send_attempts' => 0,
                'next_attempt_at' => null,
            ], 'id = ?', [$id]);
            $db->insert('outreach_message_revisions', ['message_id' => $id, 'revision' => $rev, 'subject' => $d['subject'], 'body' => $d['body'], 'edited_by' => $user->id]);
            $this->app->make(ApprovalService::class)->cancelPending('outreach_message', $id, 'Draft edited — approval invalidated');
        });
        $this->app->make(AuditLogger::class)->log('outreach.draft_edited', $user->id, (int)$m['project_id'], ['previous_status' => $m['status']], 'outreach_message', null, $id);
    }

    /** Regenerate the body/subject with the writer (creates a new revision). */
    public function regenerate(User $user, int $id, bool $useLocalModel): void
    {
        $m = $this->message($user, $id);
        $this->gate->authorizeProject($user, (int)$m['project_id'], Gate::MANAGE);
        $campaign = $this->db->fetch('SELECT * FROM outreach_campaigns WHERE id = ?', [$m['campaign_id']]);
        $prospect = $this->db->fetch('SELECT * FROM prospect_pages WHERE id = ?', [$m['prospect_page_id']]);
        $extra = [];
        $type = (string)$campaign['type'];
        if ($m['message_type'] === 'follow_up' && $m['parent_message_id']) {
            $parent = $this->db->fetch('SELECT subject, sent_at FROM outreach_messages WHERE id = ?', [$m['parent_message_id']]);
            $extra = ['original_subject' => $parent['subject'] ?? '', 'original_sent_at' => $parent['sent_at'] ?? null];
            $type = 'follow_up';
        }
        $draft = $this->generate($type, $campaign, $prospect, $extra, $useLocalModel);
        $this->updateDraft($user, $id, ['subject' => $draft['subject'], 'body' => $draft['body'], 'recipient_email' => $m['recipient_email'], 'recipient_name' => $m['recipient_name']]);
        $this->db->update('outreach_messages', ['generator' => $draft['generator'], 'generator_note' => $draft['note'] !== null ? mb_substr($draft['note'], 0, 255) : null, 'subject_alternatives' => json_encode($draft['alternatives'], JSON_UNESCAPED_UNICODE)], 'id = ?', [$id]);
    }

    public function submitForApproval(User $user, int $id): void
    {
        $m = $this->message($user, $id);
        $this->gate->authorizeProject($user, (int)$m['project_id'], Gate::MANAGE);
        if ($m['status'] !== 'draft' && $m['status'] !== 'failed') {
            throw ValidationException::single('status', 'Only drafts can be submitted for approval.');
        }
        if (!filter_var((string)$m['recipient_email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::single('recipient_email', 'Add a publicly listed professional contact email before submitting.');
        }
        $problems = ContentGuard::problems($m['subject'], $m['body'], $m['recipient_name']);
        if ($problems) {
            throw ValidationException::single('body', implode(' ', $problems));
        }
        if ($this->app->make(SuppressionService::class)->isSuppressed((string)$m['recipient_email'], (int)$m['project_id'])) {
            throw ValidationException::single('recipient_email', 'This recipient has unsubscribed or is on the suppression list.');
        }
        if (!in_array($m['prospect_status'], ['approved', 'outreach_sent'], true)) {
            throw ValidationException::single('status', 'The prospect must be approved before outreach can be submitted.');
        }
        $this->app->make(ApprovalService::class)->requestMessageApproval($user, $m);
    }

    public function cancel(User $user, int $id): void
    {
        $m = $this->message($user, $id);
        $this->gate->authorizeProject($user, (int)$m['project_id'], Gate::MANAGE);
        if (in_array($m['status'], ['sent', 'sending'], true)) {
            throw ValidationException::single('status', 'Sent messages cannot be cancelled.');
        }
        $this->db->transaction(function () use ($id): void {
            $this->db->update('outreach_messages', ['status' => 'cancelled'], 'id = ?', [$id]);
            $this->app->make(ApprovalService::class)->cancelPending('outreach_message', $id, 'Message cancelled');
        });
        $this->app->make(AuditLogger::class)->log('outreach.cancelled', $user->id, (int)$m['project_id'], [], 'outreach_message', null, $id);
    }

    public function setReplyStatus(User $user, int $id, string $status): void
    {
        $m = $this->message($user, $id);
        $this->gate->authorizeProject($user, (int)$m['project_id'], Gate::MANAGE);
        if (!in_array($status, ['none', 'replied', 'positive', 'negative', 'bounced'], true) || $m['status'] !== 'sent') {
            throw ValidationException::single('reply_status', 'Reply status can only be recorded for sent messages.');
        }
        $this->db->update('outreach_messages', ['reply_status' => $status], 'id = ?', [$id]);
        if ($status === 'bounced' && $m['recipient_email']) {
            $this->app->make(SuppressionService::class)->add((string)$m['recipient_email'], null, 'bounce', 'Marked bounced on message #' . $id, $user->id);
        }
        if ($status === 'negative') {
            // Respect "no": stop any follow-ups to this prospect.
            $this->db->run("UPDATE outreach_messages SET status = 'cancelled' WHERE parent_message_id = ? AND status IN ('draft','needs_review','approved')", [$id]);
        }
        $this->app->make(AuditLogger::class)->log('outreach.reply_recorded', $user->id, (int)$m['project_id'], ['reply_status' => $status], 'outreach_message', null, $id);
    }

    /** Create follow-up drafts for sent messages with no reply after the campaign's follow-up delay. */
    public function generateDueFollowUps(int $limit = 20): int
    {
        if (!$this->app->settings()->bool('outreach.auto_follow_up_drafts') || !$this->app->settings()->moduleEnabled('outreach')) {
            return 0;
        }
        $rows = $this->db->fetchAll(
            "SELECT m.* FROM outreach_messages m JOIN outreach_campaigns c ON c.id = m.campaign_id AND c.status = 'active'
             WHERE m.status = 'sent' AND m.reply_status = 'none' AND m.follow_up_number < c.max_follow_ups
               AND m.sent_at <= UTC_TIMESTAMP() - INTERVAL c.follow_up_days DAY
               AND NOT EXISTS (SELECT 1 FROM outreach_messages f WHERE f.parent_message_id = m.id)
             ORDER BY m.sent_at LIMIT " . max(1, $limit)
        );
        $n = 0;
        foreach ($rows as $m) {
            if ($this->createFollowUp(null, (int)$m['id']) !== null) {
                $n++;
            }
        }
        return $n;
    }

    public function createFollowUp(?User $user, int $parentId): ?int
    {
        $parent = $user ? $this->message($user, $parentId) : $this->db->fetch('SELECT * FROM outreach_messages WHERE id = ?', [$parentId]);
        if (!$parent || $parent['status'] !== 'sent' || in_array($parent['reply_status'], ['negative', 'bounced', 'positive', 'replied'], true)) {
            return null;
        }
        if ($user) {
            $this->gate->authorizeProject($user, (int)$parent['project_id'], Gate::MANAGE);
        }
        if ($this->app->make(SuppressionService::class)->isSuppressed((string)$parent['recipient_email'], (int)$parent['project_id'])) {
            return null;
        }
        $n = (int)$parent['follow_up_number'] + 1;
        $hash = self::dedupeHash((int)$parent['campaign_id'], (int)$parent['prospect_page_id'], 'follow_up', $n);
        if ($existing = $this->db->fetchColumn('SELECT id FROM outreach_messages WHERE dedupe_hash = ?', [$hash])) {
            return (int)$existing;
        }
        $campaign = $this->db->fetch('SELECT * FROM outreach_campaigns WHERE id = ?', [$parent['campaign_id']]);
        $prospect = $this->db->fetch('SELECT * FROM prospect_pages WHERE id = ?', [$parent['prospect_page_id']]);
        $draft = $this->generate('follow_up', $campaign, $prospect, ['original_subject' => $parent['subject'], 'original_sent_at' => $parent['sent_at']]);
        try {
            $id = $this->db->insert('outreach_messages', [
                'campaign_id' => (int)$parent['campaign_id'],
                'project_id' => (int)$parent['project_id'],
                'client_id' => (int)$parent['client_id'],
                'prospect_page_id' => (int)$parent['prospect_page_id'],
                'parent_message_id' => (int)$parent['id'],
                'message_type' => 'follow_up',
                'follow_up_number' => $n,
                'recipient_email' => $parent['recipient_email'],
                'recipient_name' => $parent['recipient_name'],
                'subject' => $draft['subject'],
                'body' => $draft['body'],
                'subject_alternatives' => json_encode($draft['alternatives'], JSON_UNESCAPED_UNICODE),
                'generator' => 'template',
                'status' => 'draft',
                'unsubscribe_token' => bin2hex(random_bytes(32)),
                'dedupe_hash' => $hash,
                'created_by' => $user?->id,
            ]);
            $this->db->insert('outreach_message_revisions', ['message_id' => $id, 'revision' => 1, 'subject' => $draft['subject'], 'body' => $draft['body'], 'edited_by' => $user?->id]);
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                return (int)$this->db->fetchColumn('SELECT id FROM outreach_messages WHERE dedupe_hash = ?', [$hash]);
            }
            throw $e;
        }
        $this->app->make(AuditLogger::class)->log('outreach.follow_up_drafted', $user?->id, (int)$parent['project_id'], ['parent_id' => $parentId, 'number' => $n], 'outreach_message', null, $id);
        $this->app->make(NotificationService::class)->notifyProject((int)$parent['project_id'], 'follow_up_draft', 'Follow-up draft ready for review', 'For ' . $parent['recipient_email'], url('/outreach/messages/' . $id));
        return $id;
    }

    /** Auto-draft for newly approved prospects when an active campaign of a matching type has auto drafts on. */
    public function autoDraftForProspect(int $prospectId): ?int
    {
        if (!$this->app->settings()->moduleEnabled('outreach')) {
            return null;
        }
        $p = $this->db->fetch('SELECT * FROM prospect_pages WHERE id = ?', [$prospectId]);
        if (!$p || $p['status'] !== 'approved' || $p['contact_email'] === null) {
            return null;
        }
        $type = self::CATEGORY_TEMPLATE[$p['category']] ?? 'general';
        $c = $this->db->fetch("SELECT * FROM outreach_campaigns WHERE project_id = ? AND status = 'active' AND auto_drafts = 1 AND type IN (?, 'general') ORDER BY type = ? DESC, id LIMIT 1", [$p['project_id'], $type, $type]);
        if (!$c) {
            return null;
        }
        $this->app->make(QueueService::class)->push('generate_draft', ['campaign_id' => (int)$c['id'], 'prospect_id' => $prospectId], (int)$p['project_id'], 'draft:' . $c['id'] . ':' . $prospectId, 0, 5);
        return (int)$c['id'];
    }
}
