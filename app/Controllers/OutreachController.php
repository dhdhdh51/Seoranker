<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Core\ValidationException;
use BharatSeo\Http\Response;
use BharatSeo\Security\Gate;
use BharatSeo\Services\ApprovalService;
use BharatSeo\Services\MailerService;
use BharatSeo\Services\OutreachService;
use BharatSeo\Services\Outreach\LocalModelClient;
use BharatSeo\Services\SuppressionService;

final class OutreachController extends Controller
{
    private function svc(): OutreachService
    {
        return $this->app->make(OutreachService::class);
    }

    public function index(): string
    {
        $pid = $this->projectFilter();
        return $this->view('outreach/index', [
            'title' => 'Outreach campaigns',
            'campaigns' => $this->svc()->campaigns($this->user(), $pid),
            'projectId' => $pid,
            'projects' => $this->projectOptions(),
            'smtp' => $this->app->make(MailerService::class)->isConfigured(),
            'recent' => $this->svc()->messages($this->user(), ['project_id' => $pid], 15),
        ]);
    }

    public function createCampaign(): string
    {
        $options = array_filter($this->projectOptions(), fn($l, $id) => $this->gate->can($this->user(), Gate::MANAGE, (int)$id), ARRAY_FILTER_USE_BOTH);
        return $this->view('outreach/campaign_form', ['title' => 'New campaign', 'campaign' => null, 'projects' => $options, 'projectId' => $this->req->queryInt('project_id')]);
    }

    public function storeCampaign(): Response
    {
        $id = $this->svc()->saveCampaign($this->user(), null, $this->req->all());
        $this->flash('success', 'Campaign created.');
        return $this->redirect('/outreach/campaigns/' . $id);
    }

    public function showCampaign(int $id): string
    {
        $c = $this->svc()->campaign($this->user(), $id);
        $canManage = $this->gate->can($this->user(), Gate::MANAGE, (int)$c['project_id']);
        $eligible = $canManage ? $this->app->db()->fetchAll(
            "SELECT pp.id, pp.normalized_url, pp.domain, pp.title, pp.category, pp.contact_email, pp.quality_score FROM prospect_pages pp
             WHERE pp.project_id = ? AND pp.status = 'approved'
               AND NOT EXISTS (SELECT 1 FROM outreach_messages m WHERE m.prospect_page_id = pp.id AND m.campaign_id = ?)
             ORDER BY pp.quality_score DESC LIMIT 200",
            [$c['project_id'], $id]
        ) : [];
        return $this->view('outreach/campaign', [
            'title' => 'Campaign · ' . $c['name'],
            'campaign' => $c,
            'messages' => $this->svc()->messages($this->user(), ['campaign_id' => $id]),
            'eligible' => $eligible,
            'canManage' => $canManage,
            'localAi' => $this->app->make(LocalModelClient::class)->isAvailable(),
        ]);
    }

    public function updateCampaign(int $id): Response
    {
        $this->svc()->saveCampaign($this->user(), $id, $this->req->all());
        $this->flash('success', 'Campaign saved.');
        return $this->redirect('/outreach/campaigns/' . $id);
    }

    public function createDrafts(int $id): Response
    {
        $ids = array_slice($this->req->inputIds('prospect_ids'), 0, 50);
        $useAi = (bool)$this->req->input('use_local_model');
        $made = 0;
        foreach ($ids as $pid) {
            try {
                $this->svc()->createDraft($this->user(), $id, $pid, $useAi);
                $made++;
            } catch (ValidationException $e) {
                $this->flash('warning', "Prospect #$pid: " . implode(' ', $e->errors));
            }
        }
        $this->flash($made ? 'success' : 'warning', "$made draft(s) created. Review, edit and submit them for approval.");
        return $this->redirect('/outreach/campaigns/' . $id);
    }

    public function editMessage(int $id): string
    {
        $m = $this->svc()->message($this->user(), $id);
        $canManage = $this->gate->can($this->user(), Gate::MANAGE, (int)$m['project_id']);
        $approvals = $this->app->db()->fetchAll('SELECT a.*, u.name AS decided_by_name FROM approval_requests a LEFT JOIN users u ON u.id = a.decided_by WHERE a.entity_type = \'outreach_message\' AND a.entity_id = ? ORDER BY a.id DESC', [$id]);
        return $this->view('outreach/message', [
            'title' => 'Draft editor',
            'm' => $m,
            'canManage' => $canManage,
            'revisions' => $this->svc()->revisions($id),
            'approvals' => $approvals,
            'alternatives' => json_decode((string)$m['subject_alternatives'], true) ?: [],
            'suppressed' => $m['recipient_email'] ? $this->app->make(SuppressionService::class)->isSuppressed((string)$m['recipient_email'], (int)$m['project_id']) : false,
            'smtp' => $this->app->make(MailerService::class)->isConfigured(),
            'localAi' => $this->app->make(LocalModelClient::class)->isAvailable(),
            'parties' => ApprovalService::requiredParties((string)$m['approval_policy']),
            'unsubscribeUrl' => absolute_url('/unsubscribe/' . $m['unsubscribe_token']),
        ]);
    }

    public function saveMessage(int $id): Response
    {
        $this->svc()->updateDraft($this->user(), $id, $this->req->all());
        $this->flash('success', 'Draft saved as a new revision. Any previous approval was invalidated.');
        return $this->redirect('/outreach/messages/' . $id);
    }

    public function regenerate(int $id): Response
    {
        $this->svc()->regenerate($this->user(), $id, (bool)$this->req->input('use_local_model'));
        $this->flash('success', 'Draft regenerated as a new revision.');
        return $this->redirect('/outreach/messages/' . $id);
    }

    public function submit(int $id): Response
    {
        $this->svc()->submitForApproval($this->user(), $id);
        $this->flash('success', 'Submitted for approval.');
        return $this->redirect('/outreach/messages/' . $id);
    }

    public function cancel(int $id): Response
    {
        $this->svc()->cancel($this->user(), $id);
        $this->flash('success', 'Message cancelled.');
        return $this->redirect('/outreach/messages/' . $id);
    }

    public function reply(int $id): Response
    {
        $this->svc()->setReplyStatus($this->user(), $id, (string)$this->req->input('reply_status'));
        $this->flash('success', 'Reply status recorded.');
        return $this->redirect('/outreach/messages/' . $id);
    }

    public function followUp(int $id): Response
    {
        $fid = $this->svc()->createFollowUp($this->user(), $id);
        if ($fid === null) {
            $this->flash('warning', 'A follow-up is not appropriate for this message (not sent, replied, bounced or unsubscribed).');
            return $this->redirect('/outreach/messages/' . $id);
        }
        $this->flash('success', 'Follow-up draft created.');
        return $this->redirect('/outreach/messages/' . $fid);
    }

    public function suppressions(): string
    {
        $ids = $this->gate->projectIds($this->user());
        return $this->view('outreach/suppressions', [
            'title' => 'Suppression list',
            'rows' => $this->app->make(SuppressionService::class)->list($this->user()->isSuperAdmin() ? null : ($ids ?? [])),
            'globalCount' => (int)$this->app->db()->fetchColumn("SELECT COUNT(*) FROM email_suppressions WHERE scope_key = 'global'"),
            'projects' => $this->projectOptions(),
        ]);
    }

    public function addSuppression(): Response
    {
        $pid = $this->req->inputInt('project_id');
        if ($pid) {
            $this->gate->authorizeProject($this->user(), $pid, Gate::MANAGE);
        } elseif (!$this->user()->isSuperAdmin()) {
            throw ValidationException::single('project_id', 'Select a project (only Super Admins can add global suppressions).');
        }
        $ok = $this->app->make(SuppressionService::class)->add((string)$this->req->input('email'), $pid ?: null, 'manual', mb_substr((string)$this->req->input('note', ''), 0, 255) ?: null, $this->user()->id);
        $this->flash($ok ? 'success' : 'warning', $ok ? 'Address suppressed.' : 'Invalid address or already suppressed.');
        return $this->redirect('/outreach/suppressions');
    }

    public function removeSuppression(int $id): Response
    {
        $this->app->make(SuppressionService::class)->remove($id, $this->user()->id);
        $this->flash('success', 'Suppression removed.');
        return $this->redirect('/outreach/suppressions');
    }
}
