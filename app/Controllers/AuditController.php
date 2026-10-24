<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Http\Response;
use BharatSeo\Security\Gate;
use BharatSeo\Services\Audit\PageAnalyzer;
use BharatSeo\Services\AuditLogger;
use BharatSeo\Services\SiteAuditService;
use BharatSeo\Support\Csv;

final class AuditController extends Controller
{
    private function svc(): SiteAuditService
    {
        return $this->app->make(SiteAuditService::class);
    }

    public function index(): string
    {
        $pid = $this->projectFilter();
        $manage = array_filter($this->projectOptions(), fn($l, $id) => $this->gate->can($this->user(), Gate::MANAGE, (int)$id), ARRAY_FILTER_USE_BOTH);
        return $this->view('audits/index', ['title' => 'Site audit', 'audits' => $this->svc()->list($this->user(), $pid), 'projects' => $this->projectOptions(), 'manageable' => $manage, 'projectId' => $pid]);
    }

    public function store(): Response
    {
        $id = $this->svc()->start($this->user(), (int)$this->req->inputInt('project_id'), $this->req->all());
        // Start crawling right away (bounded); the queue cron continues the rest.
        $this->svc()->processChunk($id, 20);
        $this->flash('success', 'Site audit started. Large sites continue in the background every few minutes.');
        return $this->redirect('/audits/' . $id);
    }

    public function show(int $id): string
    {
        $a = $this->svc()->find($this->user(), $id);
        $pages = $this->svc()->pages($id);
        return $this->view('audits/show', [
            'title' => 'Site audit · ' . $a['project_name'],
            'a' => $a,
            'pages' => $pages,
            'groups' => $this->svc()->issueGroups($a, $pages),
            'checks' => json_decode((string)$a['site_checks'], true) ?: [],
            'canManage' => $this->gate->can($this->user(), Gate::MANAGE, (int)$a['project_id']),
            'catalog' => PageAnalyzer::ISSUES + PageAnalyzer::SITE_ISSUES,
        ]);
    }

    public function crawlMore(int $id): Response
    {
        $a = $this->svc()->find($this->user(), $id);
        $this->gate->authorizeProject($this->user(), (int)$a['project_id'], Gate::MANAGE);
        $done = $this->svc()->processChunk($id, 25);
        $this->flash('success', $done ? 'Audit finished.' : 'Crawled another batch of pages.');
        return $this->redirect('/audits/' . $id);
    }

    public function cancel(int $id): Response
    {
        $this->svc()->cancel($this->user(), $id);
        $this->flash('success', 'Audit cancelled.');
        return $this->redirect('/audits/' . $id);
    }

    public function export(int $id): Response
    {
        $a = $this->svc()->find($this->user(), $id);
        $this->gate->authorizeProject($this->user(), (int)$a['project_id'], Gate::EXPORT);
        $pages = $this->svc()->pages($id);
        $groups = $this->svc()->issueGroups($a, $pages);
        $this->app->make(AuditLogger::class)->log('export.site_audit', $this->user()->id, (int)$a['project_id'], ['audit_id' => $id]);
        return Response::csv('site-audit-' . $id . '.csv', static function ($out) use ($groups, $pages): void {
            Csv::writeRow($out, ['Severity', 'Issue', 'How to fix', 'URL']);
            foreach ($groups as $g) {
                if ($g['site']) {
                    Csv::writeRow($out, [$g['severity'], $g['title'], $g['fix'], '(site-wide)']);
                }
                foreach ($g['pages'] as $p) {
                    Csv::writeRow($out, [$g['severity'], $g['title'], $g['fix'], $p['url']]);
                }
            }
            Csv::writeRow($out, []);
            Csv::writeRow($out, ['URL', 'HTTP', 'Title', 'Title length', 'Meta description length', 'H1 count', 'Words', 'Response ms', 'Images without alt', 'Canonical', 'Depth', 'In sitemap']);
            foreach ($pages as $p) {
                Csv::writeRow($out, [$p['url'], $p['http_status'], $p['title'], mb_strlen((string)$p['title']), mb_strlen((string)$p['meta_description']), $p['h1_count'], $p['word_count'], $p['response_ms'], $p['images_missing_alt'], $p['canonical'], $p['depth'], $p['in_sitemap'] ? 'yes' : 'no']);
            }
        });
    }
}
