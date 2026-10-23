<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Http\Response;
use BharatSeo\Security\Gate;
use BharatSeo\Services\DiscoveryService;
use BharatSeo\Services\ProjectService;
use BharatSeo\Services\SearchProviderService;

final class DiscoveryController extends Controller
{
    private function svc(): DiscoveryService
    {
        return $this->app->make(DiscoveryService::class);
    }

    public function index(): string
    {
        $projectId = $this->projectFilter();
        $options = array_filter($this->projectOptions(), fn($label, $id) => $this->gate->can($this->user(), Gate::MANAGE, (int)$id), ARRAY_FILTER_USE_BOTH);
        $targets = $projectId ? $this->app->make(ProjectService::class)->targetPages($projectId) : [];
        return $this->view('discovery/index', [
            'title' => 'Opportunity discovery',
            'projectId' => $projectId,
            'projects' => $options,
            'targets' => $targets,
            'runs' => $this->svc()->runs($this->user(), $projectId),
            'imports' => $this->svc()->imports($this->user(), $projectId),
            'search' => $this->app->make(SearchProviderService::class),
            'maxUploadMb' => (int)$this->app->settings()->get('security.upload_max_mb', 5),
        ]);
    }

    private function projectId(): int
    {
        return (int)$this->req->inputInt('project_id');
    }

    public function manual(): Response
    {
        $pid = $this->projectId();
        $res = $this->svc()->addManual($this->user(), $pid, $this->req->all());
        $msg = sprintf('%d URL(s) processed: %d new, %d duplicate, %d rejected. New prospects are queued for evaluation.', $res['total'], $res['created'], $res['duplicate'], $res['rejected']);
        $this->flash($res['created'] > 0 ? 'success' : 'warning', $msg);
        foreach (array_slice($res['errors'], 0, 5) as $err) {
            $this->flash('warning', $err);
        }
        return $this->redirect('/discovery', ['project_id' => $pid]);
    }

    public function import(): Response
    {
        $pid = $this->projectId();
        $file = $this->req->file('file');
        if ($file === null) {
            throw \BharatSeo\Core\ValidationException::single('file', 'Choose a CSV file to upload.');
        }
        $id = $this->svc()->queueImport($this->user(), $pid, $file, (string)$this->req->input('type', 'prospects'), (string)$this->req->input('category', ''));
        $this->flash('success', "Import #$id queued. It is processed in small batches by the queue cron.");
        return $this->redirect('/discovery', ['project_id' => $pid]);
    }

    public function search(): Response
    {
        $pid = $this->projectId();
        $run = $this->svc()->queueSearch($this->user(), $pid, $this->req->all());
        $this->flash('success', "Search discovery run #$run queued (counts against your provider's daily quota).");
        return $this->redirect('/discovery', ['project_id' => $pid]);
    }

    public function publicPage(): Response
    {
        $pid = $this->projectId();
        $run = $this->svc()->queuePublicPage($this->user(), $pid, $this->req->all());
        $this->flash('success', "Public page extraction run #$run queued. robots.txt is respected.");
        return $this->redirect('/discovery', ['project_id' => $pid]);
    }
}
