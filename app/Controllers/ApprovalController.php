<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Http\Response;
use BharatSeo\Services\ApprovalService;

final class ApprovalController extends Controller
{
    public function index(): string
    {
        $svc = $this->app->make(ApprovalService::class);
        $pid = $this->projectFilter();
        $messages = $svc->pendingMessages($this->user());
        if ($pid) {
            $messages = array_values(array_filter($messages, static fn($m) => (int)$m['project_id'] === $pid));
        }
        return $this->view('approvals/index', [
            'title' => 'Approval queue',
            'messages' => $messages,
            'prospects' => $svc->pendingProspects($this->user(), $pid),
            'history' => $svc->history($this->user(), 30),
            'projects' => $this->projectOptions(),
            'projectId' => $pid,
        ]);
    }

    public function decide(int $id): Response
    {
        $approve = $this->req->input('decision') === 'approve';
        $result = $this->app->make(ApprovalService::class)->decideMessage($this->user(), $id, $approve, trim((string)$this->req->input('note', '')) ?: null);
        $this->flash('success', match ($result) {
            'approved' => 'Approved. The message will be sent by the outreach cron within the configured limits.',
            'partially_approved' => 'Your approval was recorded; another party must also approve.',
            default => 'Rejected and returned to draft.',
        });
        return $this->redirect('/approvals');
    }
}
