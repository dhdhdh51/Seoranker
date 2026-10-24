<?php
declare(strict_types=1);

use BharatSeo\Controllers\ApprovalController;
use BharatSeo\Controllers\AuditController;
use BharatSeo\Controllers\SsoController;
use BharatSeo\Controllers\AuthController;
use BharatSeo\Controllers\BacklinkController;
use BharatSeo\Controllers\ClientController;
use BharatSeo\Controllers\DashboardController;
use BharatSeo\Controllers\DiscoveryController;
use BharatSeo\Controllers\NotificationController;
use BharatSeo\Controllers\OutreachController;
use BharatSeo\Controllers\ProjectController;
use BharatSeo\Controllers\ProspectController;
use BharatSeo\Controllers\PublicController;
use BharatSeo\Controllers\ReportController;
use BharatSeo\Controllers\SettingsController;
use BharatSeo\Controllers\SystemController;
use BharatSeo\Controllers\UserController;
use BharatSeo\Http\Router;
use BharatSeo\Security\User;

return static function (Router $r): void {
    $guest = ['auth' => false];
    $admin = ['roles' => [User::SUPER_ADMIN]];
    $staff = ['roles' => [User::SUPER_ADMIN, User::SEO_MANAGER]];

    // Authentication
    $r->get('/login', [AuthController::class, 'showLogin'], $guest);
    $r->post('/login', [AuthController::class, 'login'], $guest);
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->get('/forgot-password', [AuthController::class, 'showForgot'], $guest);
    $r->post('/forgot-password', [AuthController::class, 'forgot'], $guest);
    $r->get('/reset-password', [AuthController::class, 'showReset'], $guest);
    $r->post('/reset-password', [AuthController::class, 'reset'], $guest);
    $r->get('/profile', [AuthController::class, 'profile']);
    $r->post('/profile/password', [AuthController::class, 'changePassword']);

    // Public unsubscribe (RFC 8058 one-click POST has no session/CSRF; the 256-bit token authorizes it)
    $r->get('/unsubscribe/{token}', [PublicController::class, 'unsubscribeForm'], $guest);
    $r->post('/unsubscribe/{token}', [PublicController::class, 'unsubscribe'], ['auth' => false, 'csrf' => false]);

    // Dashboard
    $r->get('/', [DashboardController::class, 'index']);

    // Clients
    $r->get('/clients', [ClientController::class, 'index'], $staff);
    $r->get('/clients/new', [ClientController::class, 'create'], $admin);
    $r->post('/clients', [ClientController::class, 'store'], $admin);
    $r->get('/clients/{id}', [ClientController::class, 'show'], $staff);
    $r->get('/clients/{id}/edit', [ClientController::class, 'edit'], $admin);
    $r->post('/clients/{id}', [ClientController::class, 'update'], $admin);
    $r->post('/clients/{id}/users', [ClientController::class, 'attachUser'], $admin);
    $r->post('/clients/{id}/users/{uid}/remove', [ClientController::class, 'detachUser'], $admin);

    // Projects
    $r->get('/projects', [ProjectController::class, 'index']);
    $r->get('/projects/new', [ProjectController::class, 'create'], $staff);
    $r->post('/projects', [ProjectController::class, 'store'], $staff);
    $r->get('/projects/{id}', [ProjectController::class, 'show']);
    $r->get('/projects/{id}/edit', [ProjectController::class, 'edit'], $staff);
    $r->post('/projects/{id}', [ProjectController::class, 'update'], $staff);
    $r->post('/projects/{id}/status', [ProjectController::class, 'status'], $staff);
    $r->post('/projects/{id}/verify', [ProjectController::class, 'verify'], $staff);
    $r->post('/projects/{id}/sitemap', [ProjectController::class, 'sitemap'], $staff);
    $r->post('/projects/{id}/members', [ProjectController::class, 'addMember'], $admin);
    $r->post('/projects/{id}/members/{uid}/remove', [ProjectController::class, 'removeMember'], $admin);
    $r->post('/projects/{id}/client-users', [ProjectController::class, 'addClientUser'], $admin);
    $r->post('/projects/{id}/client-users/{uid}/remove', [ProjectController::class, 'removeClientUser'], $admin);
    $r->post('/projects/{id}/settings', [ProjectController::class, 'settings'], $staff);
    $r->post('/projects/{id}/check-backlinks', [ProjectController::class, 'checkBacklinks'], $staff + ['module' => 'monitoring']);

    // Discovery
    $r->get('/discovery', [DiscoveryController::class, 'index'], $staff + ['module' => 'discovery']);
    $r->post('/discovery/manual', [DiscoveryController::class, 'manual'], $staff + ['module' => 'discovery']);
    $r->post('/discovery/import', [DiscoveryController::class, 'import'], $staff + ['module' => 'discovery']);
    $r->post('/discovery/search', [DiscoveryController::class, 'search'], $staff + ['module' => 'discovery']);
    $r->post('/discovery/public-page', [DiscoveryController::class, 'publicPage'], $staff + ['module' => 'discovery']);

    // Prospects
    $r->get('/prospects', [ProspectController::class, 'index']);
    $r->post('/prospects/bulk', [ProspectController::class, 'bulk'], $staff);
    $r->get('/prospects/{id}', [ProspectController::class, 'show']);
    $r->get('/prospects/{id}/assessment', [ProspectController::class, 'assessment']);
    $r->post('/prospects/{id}', [ProspectController::class, 'update'], $staff);
    $r->post('/prospects/{id}/evaluate', [ProspectController::class, 'evaluate'], $staff);
    $r->post('/prospects/{id}/decision', [ProspectController::class, 'decision'], $staff);
    $r->post('/prospects/{id}/hold-release', [ProspectController::class, 'releaseHold'], $staff);
    $r->post('/prospects/{id}/status', [ProspectController::class, 'status'], $staff);
    $r->post('/prospects/{id}/submitted', [ProspectController::class, 'submitted'], $staff);
    $r->post('/prospects/{id}/track', [ProspectController::class, 'track'], $staff + ['module' => 'monitoring']);
    $r->post('/prospects/{id}/scan-broken', [ProspectController::class, 'scanBroken'], $staff);

    // Outreach
    $o = ['module' => 'outreach'];
    $r->get('/outreach', [OutreachController::class, 'index'], $o);
    $r->get('/outreach/campaigns/new', [OutreachController::class, 'createCampaign'], $staff + $o);
    $r->post('/outreach/campaigns', [OutreachController::class, 'storeCampaign'], $staff + $o);
    $r->get('/outreach/campaigns/{id}', [OutreachController::class, 'showCampaign'], $o);
    $r->post('/outreach/campaigns/{id}', [OutreachController::class, 'updateCampaign'], $staff + $o);
    $r->post('/outreach/campaigns/{id}/drafts', [OutreachController::class, 'createDrafts'], $staff + $o);
    $r->get('/outreach/messages/{id}', [OutreachController::class, 'editMessage'], $o);
    $r->post('/outreach/messages/{id}', [OutreachController::class, 'saveMessage'], $staff + $o);
    $r->post('/outreach/messages/{id}/regenerate', [OutreachController::class, 'regenerate'], $staff + $o);
    $r->post('/outreach/messages/{id}/submit', [OutreachController::class, 'submit'], $staff + $o);
    $r->post('/outreach/messages/{id}/cancel', [OutreachController::class, 'cancel'], $staff + $o);
    $r->post('/outreach/messages/{id}/reply', [OutreachController::class, 'reply'], $staff + $o);
    $r->post('/outreach/messages/{id}/follow-up', [OutreachController::class, 'followUp'], $staff + $o);
    $r->get('/outreach/suppressions', [OutreachController::class, 'suppressions'], $staff);
    $r->post('/outreach/suppressions', [OutreachController::class, 'addSuppression'], $staff);
    $r->post('/outreach/suppressions/{id}/remove', [OutreachController::class, 'removeSuppression'], $admin);

    // Approvals
    $r->get('/approvals', [ApprovalController::class, 'index']);
    $r->post('/approvals/{id}/decide', [ApprovalController::class, 'decide']);

    // Backlinks
    $m = ['module' => 'monitoring'];
    $r->get('/backlinks', [BacklinkController::class, 'index'], $m);
    $r->post('/backlinks', [BacklinkController::class, 'store'], $staff + $m);
    $r->get('/backlinks/history', [BacklinkController::class, 'history'], $m);
    $r->get('/backlinks/{id}', [BacklinkController::class, 'show'], $m);
    $r->post('/backlinks/{id}', [BacklinkController::class, 'update'], $staff + $m);
    $r->post('/backlinks/{id}/check', [BacklinkController::class, 'check'], $staff + $m);
    $r->post('/backlinks/{id}/delete', [BacklinkController::class, 'delete'], $staff + $m);

    // Reports & exports
    $r->get('/reports', [ReportController::class, 'index'], ['module' => 'reports']);
    $r->get('/reports/project/{id}', [ReportController::class, 'project'], ['module' => 'reports']);
    $r->get('/exports/{type}', [ReportController::class, 'export'], ['module' => 'reports']);

    // Site audit
    $au = ['module' => 'audit'];
    $r->get('/audits', [AuditController::class, 'index'], $au);
    $r->post('/audits', [AuditController::class, 'store'], $staff + $au);
    $r->get('/audits/{id}', [AuditController::class, 'show'], $au);
    $r->post('/audits/{id}/continue', [AuditController::class, 'crawlMore'], $staff + $au);
    $r->post('/audits/{id}/cancel', [AuditController::class, 'cancel'], $staff + $au);
    $r->get('/audits/{id}/export', [AuditController::class, 'export'], $au);

    // Single sign-on to the company website admin
    $r->get('/sso/website', [SsoController::class, 'website'], $staff);

    // Notifications
    $r->get('/notifications', [NotificationController::class, 'index']);
    $r->post('/notifications/read-all', [NotificationController::class, 'readAll']);
    $r->post('/notifications/{id}/read', [NotificationController::class, 'read']);

    // Users & roles
    $r->get('/users', [UserController::class, 'index'], $admin);
    $r->get('/users/new', [UserController::class, 'create'], $admin);
    $r->post('/users', [UserController::class, 'store'], $admin);
    $r->get('/users/{id}/edit', [UserController::class, 'edit'], $admin);
    $r->post('/users/{id}', [UserController::class, 'update'], $admin);
    $r->post('/users/{id}/reset-link', [UserController::class, 'resetLink'], $admin);

    // Settings
    $r->get('/settings', [SettingsController::class, 'index'], $admin);
    $r->post('/settings', [SettingsController::class, 'save'], $admin);
    $r->post('/settings/test-smtp', [SettingsController::class, 'testSmtp'], $admin);
    $r->post('/settings/test-ai', [SettingsController::class, 'testAi'], $admin);

    // System
    $r->get('/system/queue', [SystemController::class, 'queue'], $admin);
    $r->post('/system/queue/pause', [SystemController::class, 'pause'], $admin);
    $r->post('/system/queue/resume', [SystemController::class, 'resume'], $admin);
    $r->post('/system/queue/run', [SystemController::class, 'runBatch'], $admin);
    $r->post('/system/failed/{id}/retry', [SystemController::class, 'retryFailed'], $admin);
    $r->post('/system/failed/{id}/dismiss', [SystemController::class, 'dismissFailed'], $admin);
    $r->get('/system/audit', [SystemController::class, 'audit'], $admin);
    $r->post('/system/audit/verify', [SystemController::class, 'verifyAudit'], $admin);
    $r->get('/system/install', [SystemController::class, 'install'], $admin);
    $r->post('/system/migrate', [SystemController::class, 'migrate'], $admin);
};
