<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Services\CronService;
use BharatSeo\Services\DashboardService;

final class DashboardController extends Controller
{
    public function index(): string
    {
        $user = $this->user();
        $projectId = $this->projectFilter();
        $from = (string)$this->req->query('from', '');
        $to = (string)$this->req->query('to', '');
        $filters = ['project_id' => $projectId, 'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '', 'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : ''];
        $dash = $this->app->make(DashboardService::class);
        $cron = $this->app->make(CronService::class);
        return $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'filters' => $filters,
            'stats' => $dash->stats($user, $filters),
            'activity' => $dash->activity($user, $projectId, 12),
            'warnings' => $user->isStaff() ? $cron->warnings() : [],
            'automationActive' => $cron->isAutomationActive(),
            'projects' => $this->projectOptions(),
        ]);
    }
}
