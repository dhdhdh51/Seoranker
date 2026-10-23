<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Core\HttpException;
use BharatSeo\Core\ValidationException;
use BharatSeo\Http\Response;
use BharatSeo\Security\Gate;
use BharatSeo\Services\ApprovalService;
use BharatSeo\Services\BacklinkService;
use BharatSeo\Services\OutreachService;
use BharatSeo\Services\ProjectService;
use BharatSeo\Services\ProspectService;
use BharatSeo\Services\QueueService;
use BharatSeo\Services\QualityScorer;

final class ProspectController extends Controller
{
    private function svc(): ProspectService
    {
        return $this->app->make(ProspectService::class);
    }

    private function filters(): array
    {
        $f = [];
        foreach (['status', 'category', 'source', 'band', 'risk', 'q', 'from', 'to', 'sort'] as $k) {
            $v = $this->req->query($k);
            $f[$k] = is_string($v) ? mb_substr($v, 0, 100) : '';
        }
        $f['project_id'] = $this->projectFilter();
        return $f;
    }

    public function index(): string
    {
        $f = $this->filters();
        $res = $this->svc()->list($this->user(), $f, $this->page(), 25);
        return $this->view('prospects/index', ['title' => 'Prospects', 'filters' => $f, 'projects' => $this->projectOptions()] + $res);
    }

    public function show(int $id): string
    {
        $p = $this->svc()->find($this->user(), $id);
        $user = $this->user();
        $canManage = $this->gate->can($user, Gate::MANAGE, (int)$p['project_id']);
        $assessment = $this->app->db()->fetch('SELECT * FROM quality_assessments WHERE prospect_page_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1', [$id]);
        return $this->view('prospects/show', [
            'title' => 'Prospect · ' . $p['domain'],
            'p' => $p,
            'assessment' => $assessment,
            'flags' => json_decode((string)$p['risk_flags'], true) ?: [],
            'canManage' => $canManage,
            'targets' => $this->app->make(ProjectService::class)->targetPages((int)$p['project_id']),
            'timeline' => $user->isStaff() ? $this->svc()->timeline($id) : [],
            'campaigns' => $canManage ? $this->app->make(OutreachService::class)->campaigns($user, (int)$p['project_id']) : [],
            'messages' => $this->app->db()->fetchAll('SELECT id, subject, status, message_type, sent_at, reply_status FROM outreach_messages WHERE prospect_page_id = ? ORDER BY id DESC', [$id]),
            'backlinks' => $this->app->db()->fetchAll('SELECT id, status, lifecycle, target_url, last_checked_at FROM backlink_records WHERE prospect_page_id = ?', [$id]),
        ]);
    }

    public function assessment(int $id): string
    {
        $p = $this->svc()->find($this->user(), $id);
        return $this->view('prospects/assessment', [
            'title' => 'Quality assessment · ' . $p['domain'],
            'p' => $p,
            'assessments' => $this->svc()->assessments($id),
            'weights' => QualityScorer::WEIGHTS,
        ]);
    }

    public function update(int $id): Response
    {
        $this->svc()->update($this->user(), $id, $this->req->all());
        $this->flash('success', 'Prospect updated.');
        return $this->redirect('/prospects/' . $id);
    }

    public function evaluate(int $id): Response
    {
        $this->svc()->requeueEvaluation($this->user(), $id);
        $this->flash('success', 'Re-evaluation queued.');
        return $this->redirect('/prospects/' . $id);
    }

    public function decision(int $id): Response
    {
        $approve = $this->req->input('decision') === 'approve';
        $this->app->make(ApprovalService::class)->decideProspect($this->user(), $id, $approve, trim((string)$this->req->input('note', '')) ?: null);
        $this->flash('success', $approve ? 'Prospect approved.' : 'Prospect rejected.');
        return $this->back();
    }

    public function releaseHold(int $id): Response
    {
        $this->svc()->releaseHold($this->user(), $id, (string)$this->req->input('reason', ''));
        $this->flash('success', 'Manual hold released. The decision and reason were recorded in the audit log.');
        return $this->redirect('/prospects/' . $id);
    }

    /** Manual workflow transitions (reopen, mark failed, reject, mark verified). */
    public function status(int $id): Response
    {
        $p = $this->svc()->find($this->user(), $id);
        $this->gate->authorizeProject($this->user(), (int)$p['project_id'], Gate::MANAGE);
        $to = (string)$this->req->input('status');
        if (!in_array($to, ['needs_review', 'rejected', 'failed', 'verified'], true)) {
            throw new HttpException(422);
        }
        if ($to === 'verified' && !$this->app->db()->fetchColumn("SELECT 1 FROM backlink_records WHERE prospect_page_id = ? AND lifecycle = 'confirmed'", [$id])) {
            throw ValidationException::single('status', 'A prospect is verified only after the backlink monitor has observed the link. Track it in Backlink Monitor.');
        }
        $this->svc()->transition($id, $to, $this->user()->id, trim((string)$this->req->input('note', '')) ?: null);
        $this->flash('success', 'Status updated.');
        return $this->redirect('/prospects/' . $id);
    }

    public function submitted(int $id): Response
    {
        $this->svc()->markSubmitted($this->user(), $id, (string)$this->req->input('external_submission_id', ''), trim((string)$this->req->input('note', '')) ?: null);
        $this->flash('success', 'Manual submission recorded. Track the expected link in Backlink Monitor to verify it.');
        return $this->redirect('/prospects/' . $id);
    }

    public function track(int $id): Response
    {
        $bid = $this->app->make(BacklinkService::class)->trackFromProspect($this->user(), $id);
        $this->flash('success', 'Backlink tracking started; the first check is queued.');
        return $this->redirect('/backlinks/' . $bid);
    }

    public function scanBroken(int $id): Response
    {
        $p = $this->svc()->find($this->user(), $id);
        $this->gate->authorizeProject($this->user(), (int)$p['project_id'], Gate::MANAGE);
        $this->app->make(QueueService::class)->push('scan_broken_links', ['prospect_id' => $id, 'user_id' => $this->user()->id], (int)$p['project_id'], 'broken:' . $id, 0, 4, 3);
        $this->flash('success', 'Broken-link scan queued (up to 15 outbound links, rate-limited, robots.txt respected).');
        return $this->redirect('/prospects/' . $id);
    }

    public function bulk(): Response
    {
        $ids = array_slice($this->req->inputIds('ids'), 0, 200);
        $action = (string)$this->req->input('action');
        if ($ids === []) {
            $this->flash('warning', 'Select at least one prospect.');
            return $this->back();
        }
        $ok = 0;
        $errors = [];
        $approvals = $this->app->make(ApprovalService::class);
        foreach ($ids as $id) {
            try {
                match ($action) {
                    'approve' => $approvals->decideProspect($this->user(), $id, true, 'Bulk approval'),
                    'reject' => $approvals->decideProspect($this->user(), $id, false, (string)$this->req->input('note', 'Bulk rejection') ?: 'Bulk rejection'),
                    'review' => $this->svc()->transition($id, 'needs_review', $this->user()->id, 'Sent to review'),
                    'evaluate' => $this->svc()->requeueEvaluation($this->user(), $id),
                    default => throw new HttpException(422),
                };
                $ok++;
            } catch (ValidationException $e) {
                $errors[] = "#$id: " . implode(' ', $e->errors);
            } catch (HttpException $e) {
                if ($e->status === 422) {
                    throw $e;
                }
                $errors[] = "#$id: " . $e->getMessage();
            }
        }
        $this->flash($ok ? 'success' : 'warning', "$ok prospect(s) updated.");
        foreach (array_slice($errors, 0, 5) as $err) {
            $this->flash('warning', $err);
        }
        return $this->back();
    }
}
