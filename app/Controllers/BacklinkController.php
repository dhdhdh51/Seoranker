<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Http\Response;
use BharatSeo\Security\Gate;
use BharatSeo\Services\BacklinkService;
use BharatSeo\Services\CronService;
use BharatSeo\Services\ProjectService;

final class BacklinkController extends Controller
{
    private function svc(): BacklinkService
    {
        return $this->app->make(BacklinkService::class);
    }

    public function index(): string
    {
        $f = [
            'project_id' => $this->projectFilter(),
            'status' => (string)$this->req->query('status', ''),
            'lifecycle' => (string)$this->req->query('lifecycle', ''),
            'q' => mb_substr((string)$this->req->query('q', ''), 0, 100),
        ];
        $targets = $f['project_id'] ? $this->app->make(ProjectService::class)->targetPages($f['project_id']) : [];
        return $this->view('backlinks/index', [
            'title' => 'Backlink monitor',
            'filters' => $f,
            'projects' => $this->projectOptions(),
            'targets' => $targets,
            'canManage' => $f['project_id'] ? $this->gate->can($this->user(), Gate::MANAGE, $f['project_id']) : false,
            'automationActive' => $this->app->make(CronService::class)->isAutomationActive(),
        ] + $this->svc()->list($this->user(), $f, $this->page()));
    }

    public function store(): Response
    {
        $pid = (int)$this->req->inputInt('project_id');
        $res = $this->svc()->addCandidates($this->user(), $pid, $this->req->all());
        $this->flash('success', sprintf('%d added, %d already tracked, %d rejected. Each new link starts as “Unverified” until our crawler observes it.', $res['created'], $res['duplicate'], $res['rejected']));
        foreach (array_slice($res['errors'], 0, 5) as $e) {
            $this->flash('warning', $e);
        }
        return $this->redirect('/backlinks', ['project_id' => $pid]);
    }

    public function show(int $id): string
    {
        $b = $this->svc()->find($this->user(), $id);
        return $this->view('backlinks/show', [
            'title' => 'Backlink · ' . $b['referring_domain'],
            'b' => $b,
            'history' => $this->svc()->history($id),
            'canManage' => $this->gate->can($this->user(), Gate::MANAGE, (int)$b['project_id']),
        ]);
    }

    public function history(): string
    {
        $pid = $this->projectFilter();
        [$scope, $params] = $this->gate->scope($this->user(), 'h.project_id');
        $sql = "SELECT h.*, b.referring_page_url, b.referring_domain, b.target_url, p.name AS project_name FROM backlink_check_history h
                JOIN backlink_records b ON b.id = h.backlink_id JOIN projects p ON p.id = h.project_id WHERE 1=1$scope";
        if ($pid) {
            $sql .= ' AND h.project_id = ?';
            $params[] = $pid;
        }
        if ($this->req->query('changes') === '1') {
            $sql .= ' AND h.status_changed = 1';
        }
        $rows = $this->app->db()->fetchAll($sql . ' ORDER BY h.id DESC LIMIT 300', $params);
        return $this->view('backlinks/history', ['title' => 'Backlink history', 'rows' => $rows, 'projects' => $this->projectOptions(), 'projectId' => $pid, 'changesOnly' => $this->req->query('changes') === '1']);
    }

    public function update(int $id): Response
    {
        $this->svc()->updateRecord($this->user(), $id, $this->req->all());
        $this->flash('success', 'Saved.');
        return $this->redirect('/backlinks/' . $id);
    }

    public function check(int $id): Response
    {
        $this->svc()->checkNow($this->user(), $id);
        $this->flash('success', 'Check queued with high priority. Negative results are only applied after repeated, spaced confirmations.');
        return $this->redirect('/backlinks/' . $id);
    }

    public function delete(int $id): Response
    {
        $b = $this->svc()->find($this->user(), $id);
        $this->svc()->delete($this->user(), $id);
        $this->flash('success', 'Backlink record deleted (recorded in audit log).');
        return $this->redirect('/backlinks', ['project_id' => $b['project_id']]);
    }
}
