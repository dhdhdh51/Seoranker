<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Core\HttpException;
use BharatSeo\Http\Response;
use BharatSeo\Http\View;
use BharatSeo\Security\Gate;
use BharatSeo\Services\ReportService;

final class ReportController extends Controller
{
    public const EXPORT_TYPES = ['prospects', 'backlinks', 'history', 'drafts', 'report'];

    public function index(): string|Response
    {
        // Non-JS fallbacks for the two selector forms.
        if ($pid = $this->req->queryInt('project')) {
            return $this->redirect('/reports/project/' . $pid, array_filter(['from' => (string)$this->req->query('from', ''), 'to' => (string)$this->req->query('to', '')]));
        }
        $type = (string)$this->req->query('type', '');
        if (in_array($type, self::EXPORT_TYPES, true)) {
            return $this->redirect('/exports/' . $type, array_filter(['project_id' => $this->req->queryInt('project_id')]));
        }
        $exports = [];
        [$scope, $params] = $this->gate->scope($this->user(), 'e.project_id');
        if ($this->user()->isSuperAdmin()) {
            $exports = $this->app->db()->fetchAll('SELECT e.*, p.name AS project_name, u.name AS user_name FROM export_jobs e LEFT JOIN projects p ON p.id = e.project_id LEFT JOIN users u ON u.id = e.user_id ORDER BY e.id DESC LIMIT 20');
        } else {
            $exports = $this->app->db()->fetchAll("SELECT e.*, p.name AS project_name, u.name AS user_name FROM export_jobs e JOIN projects p ON p.id = e.project_id LEFT JOIN users u ON u.id = e.user_id WHERE 1=1$scope ORDER BY e.id DESC LIMIT 20", $params);
        }
        return $this->view('reports/index', ['title' => 'Reports & exports', 'projects' => $this->projectOptions(), 'exports' => $exports, 'projectId' => $this->projectFilter()]);
    }

    public function project(int $id): string
    {
        $from = (string)$this->req->query('from', '');
        $to = (string)$this->req->query('to', '');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : null;
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : null;
        $report = $this->app->make(ReportService::class)->projectReport($this->user(), $id, $from, $to);
        $this->app->make(\BharatSeo\Services\AuditLogger::class)->log('report.viewed', $this->user()->id, $id);
        return View::render('reports/project', ['title' => 'Report · ' . $report['project']['name'], 'r' => $report, 'canExport' => $this->gate->can($this->user(), Gate::EXPORT, $id)], 'layouts/print');
    }

    public function export(string $type): Response
    {
        if (!in_array($type, self::EXPORT_TYPES, true)) {
            throw new HttpException(404);
        }
        $f = [];
        foreach (['status', 'category', 'source', 'band', 'risk', 'q', 'from', 'to', 'lifecycle'] as $k) {
            $v = $this->req->query($k);
            $f[$k] = is_string($v) ? mb_substr($v, 0, 100) : '';
        }
        $f['project_id'] = $this->projectFilter();
        $f['campaign_id'] = $this->req->queryInt('campaign_id');
        $user = $this->user();
        $svc = $this->app->make(ReportService::class);
        // Validate permissions before streaming headers.
        if ($f['project_id']) {
            $this->gate->authorizeProject($user, $f['project_id'], Gate::EXPORT);
        }
        $name = 'bharatseo-' . $type . ($f['project_id'] ? '-p' . $f['project_id'] : '') . '-' . local_now('Ymd-His') . '.csv';
        // Buffer to a temp stream so permission/validation errors become proper error pages.
        $tmp = fopen('php://temp', 'w+b');
        $svc->export($user, $type, $f, $tmp);
        return Response::csv($name, static function ($out) use ($tmp): void {
            rewind($tmp);
            stream_copy_to_stream($tmp, $out);
            fclose($tmp);
        });
    }
}
