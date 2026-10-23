<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Http\Response;
use BharatSeo\Security\Gate;
use BharatSeo\Services\DashboardService;
use BharatSeo\Services\ProjectService;
use BharatSeo\Services\QueueService;
use BharatSeo\Services\UserService;

final class ProjectController extends Controller
{
    private function svc(): ProjectService
    {
        return $this->app->make(ProjectService::class);
    }

    public function index(): string
    {
        $filters = ['status' => (string)$this->req->query('status', ''), 'q' => mb_substr((string)$this->req->query('q', ''), 0, 100), 'client_id' => $this->req->queryInt('client_id')];
        return $this->view('projects/index', ['title' => 'Projects', 'filters' => $filters] + $this->svc()->list($this->user(), $filters, $this->page()));
    }

    public function create(): string|Response
    {
        $clients = $this->svc()->creatableClients($this->user());
        if ($clients === []) {
            $this->flash('warning', $this->user()->isSuperAdmin() ? 'Create a client first.' : 'You can create projects only for clients you already manage. Ask a Super Admin to assign you.');
            return $this->redirect($this->user()->isSuperAdmin() ? '/clients/new' : '/projects');
        }
        return $this->view('projects/form', ['title' => 'New project', 'project' => null, 'clients' => $clients, 'keywords' => '', 'topics' => '', 'targets' => '', 'preselect' => $this->req->queryInt('client_id')]);
    }

    public function store(): Response
    {
        $id = $this->svc()->create($this->user(), $this->req->all());
        $this->flash('success', 'Project created. Verify site ownership to unlock site-crawling features.');
        return $this->redirect('/projects/' . $id);
    }

    public function show(int $id): string
    {
        $user = $this->user();
        $p = $this->svc()->find($user, $id);
        $svc = $this->svc();
        return $this->view('projects/show', [
            'title' => $p['name'],
            'project' => $p,
            'overview' => $svc->overview($id),
            'keywords' => $svc->keywords($id, 'keyword'),
            'topics' => $svc->keywords($id, 'topic'),
            'targets' => $svc->targetPages($id),
            'members' => $user->isStaff() ? $svc->members($id) : [],
            'managerOptions' => $user->isSuperAdmin() ? $this->app->make(UserService::class)->managers() : [],
            'verification' => $svc->verificationInstructions($p),
            'canManage' => $this->gate->can($user, Gate::MANAGE, $id),
            'activity' => $this->app->make(DashboardService::class)->activity($user, $id, 10),
        ]);
    }

    public function edit(int $id): string
    {
        $p = $this->svc()->find($this->user(), $id, Gate::MANAGE);
        return $this->view('projects/form', [
            'title' => 'Edit project',
            'project' => $p,
            'clients' => [$p['client_id'] => $p['client_name']],
            'keywords' => implode("\n", $this->svc()->keywords($id, 'keyword')),
            'topics' => implode("\n", $this->svc()->keywords($id, 'topic')),
            'targets' => implode("\n", array_column($this->svc()->targetPages($id), 'url')),
            'preselect' => $p['client_id'],
        ]);
    }

    public function update(int $id): Response
    {
        $this->svc()->update($this->user(), $id, $this->req->all());
        $this->flash('success', 'Project saved.');
        return $this->redirect('/projects/' . $id);
    }

    public function status(int $id): Response
    {
        $this->svc()->setStatus($this->user(), $id, (string)$this->req->input('status'));
        $this->flash('success', 'Project status updated.');
        return $this->redirect('/projects/' . $id);
    }

    public function verify(int $id): Response
    {
        $r = $this->svc()->verifyOwnership($this->user(), $id, (string)$this->req->input('method'));
        $this->flash($r['ok'] ? 'success' : 'danger', $r['message']);
        return $this->redirect('/projects/' . $id);
    }

    public function sitemap(int $id): Response
    {
        $n = $this->svc()->importSitemap($this->user(), $id);
        $this->flash('success', "$n target page(s) imported from sitemap.xml.");
        return $this->redirect('/projects/' . $id);
    }

    public function addMember(int $id): Response
    {
        $this->svc()->find($this->user(), $id);
        $this->svc()->addMember($this->user(), $id, (int)$this->req->inputInt('user_id'), (string)$this->req->input('role', 'manager'));
        $this->flash('success', 'Team member saved.');
        return $this->redirect('/projects/' . $id);
    }

    public function removeMember(int $id, int $uid): Response
    {
        $this->svc()->removeMember($this->user(), $id, $uid);
        $this->flash('success', 'Team member removed.');
        return $this->redirect('/projects/' . $id);
    }

    public function settings(int $id): Response
    {
        $this->svc()->find($this->user(), $id, Gate::MANAGE);
        $on = (bool)$this->req->input('auto_search');
        $this->app->settings()->setProject($id, 'auto_search', $on ? '1' : '0');
        $this->app->make(\BharatSeo\Services\AuditLogger::class)->log('project.settings_updated', $this->user()->id, $id, ['auto_search' => $on], 'project', null, $id);
        $this->flash('success', 'Project automation settings saved.');
        return $this->redirect('/projects/' . $id);
    }

    /** Quick action: queue checks for every tracked backlink of the project (bounded, idempotent). */
    public function checkBacklinks(int $id): Response
    {
        $this->svc()->find($this->user(), $id, Gate::MANAGE);
        $rows = $this->app->db()->fetchAll('SELECT id FROM backlink_records WHERE project_id = ? ORDER BY last_checked_at IS NULL DESC, last_checked_at ASC LIMIT 500', [$id]);
        $q = $this->app->make(QueueService::class);
        foreach ($rows as $r) {
            $q->push('check_backlink', ['backlink_id' => (int)$r['id']], $id, 'backlink:' . $r['id'], 0, 3);
        }
        $this->flash('success', count($rows) . ' backlink check(s) queued. Results appear after the queue cron runs.');
        return $this->redirect('/backlinks', ['project_id' => $id]);
    }
}
