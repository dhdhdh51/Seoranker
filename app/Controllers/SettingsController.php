<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Core\ValidationException;
use BharatSeo\Http\Response;
use BharatSeo\Services\AuditLogger;
use BharatSeo\Services\MailerService;
use BharatSeo\Services\Outreach\LocalModelClient;
use BharatSeo\Services\SettingsService;

final class SettingsController extends Controller
{
    public const GROUPS = [
        'General' => ['app.agency_name', 'app.timezone'],
        'Modules' => ['module.discovery', 'module.outreach', 'module.monitoring', 'module.reports', 'module.audit', 'module.search_api', 'module.local_ai'],
        'Outreach limits' => ['outreach.daily_limit', 'outreach.per_project_daily_limit', 'outreach.domain_cooldown_days', 'outreach.max_send_attempts', 'outreach.auto_follow_up_drafts', 'outreach.physical_address'],
        'SMTP' => ['smtp.driver', 'smtp.host', 'smtp.port', 'smtp.encryption', 'smtp.username', 'smtp.password', 'smtp.from_email', 'smtp.from_name', 'smtp.timeout'],
        'Crawler & request limits' => ['crawler.user_agent', 'crawler.connect_timeout', 'crawler.timeout', 'crawler.max_bytes', 'crawler.max_redirects', 'crawler.domain_interval_ms', 'crawler.retries', 'crawler.daily_fetch_limit'],
        'Backlink monitoring' => ['monitor.interval_days', 'monitor.retry_hours', 'monitor.confirmations', 'monitor.failure_threshold', 'monitor.max_checks_per_run'],
        'Discovery & search API (optional)' => ['search.provider', 'search.api_key', 'search.cse_id', 'search.searxng_url', 'search.daily_quota', 'discovery.max_results_per_query', 'discovery.public_page_max_links', 'discovery.blocklist', 'safebrowsing.api_key'],
        'Local AI model (optional)' => ['ai.endpoint', 'ai.model', 'ai.api_key', 'ai.timeout'],
        'Site audit' => ['audit.interval_ms'],
        'Website single sign-on' => ['sso.website_enabled', 'sso.website_url', 'sso.secret', 'sso.roles'],
        'Queue & cron' => ['queue.max_runtime_seconds', 'queue.max_jobs_per_run', 'queue.default_max_attempts', 'cron.expected_interval_minutes'],
        'Security' => ['security.session_idle_minutes', 'security.upload_max_mb'],
    ];

    public function index(): string
    {
        $s = $this->app->settings();
        $values = [];
        foreach (self::GROUPS as $keys) {
            foreach ($keys as $k) {
                $def = SettingsService::DEFINITIONS[$k];
                $values[$k] = $def['type'] === 'secret' ? ($s->has($k) ? '__SET__' : '') : $s->get($k);
            }
        }
        return $this->view('settings/index', ['title' => 'Settings', 'groups' => self::GROUPS, 'values' => $values, 'defs' => SettingsService::DEFINITIONS, 'production' => $this->app->config->isProduction()]);
    }

    public function save(): Response
    {
        $input = $this->req->input('settings', []);
        $clear = $this->req->input('clear', []);
        if (!is_array($input)) {
            throw ValidationException::single('settings', 'Invalid input.');
        }
        $s = $this->app->settings();
        $errors = [];
        $changes = [];
        $allowed = array_merge(...array_values(self::GROUPS));
        foreach ($allowed as $key) {
            $def = SettingsService::DEFINITIONS[$key];
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $raw = $input[$key];
            if ($def['type'] === 'secret') {
                if (is_array($clear) && !empty($clear[$key])) {
                    $s->set($key, '', $this->user()->id);
                    $changes[] = $key . ' (cleared)';
                    continue;
                }
                if ($raw === '' || $raw === null) {
                    continue; // unchanged
                }
            }
            if ($def['type'] === 'bool') {
                $raw = in_array($raw, ['1', 'on'], true);
            }
            [$ok, $value, $error] = SettingsService::validate($key, is_scalar($raw) ? $raw : '');
            if (!$ok) {
                $errors[$key] = (string)$error;
                continue;
            }
            if ($key === 'smtp.driver' && $value === 'log' && $this->app->config->isProduction()) {
                $errors[$key] = 'The log mail driver is only available in development mode.';
                continue;
            }
            if ((string)$s->get($key) !== (string)(is_bool($value) ? (int)$value : $value) || $def['type'] === 'secret') {
                $s->set($key, $value, $this->user()->id);
                $changes[] = $key;
            }
        }
        if ($changes) {
            $this->app->make(AuditLogger::class)->log('settings.updated', $this->user()->id, null, ['keys' => $changes]);
        }
        if ($errors) {
            throw new ValidationException($errors, implode(' ', $errors));
        }
        $this->flash('success', $changes ? 'Settings saved (' . count($changes) . ' changed).' : 'No changes.');
        return $this->redirect('/settings');
    }

    public function testSmtp(): Response
    {
        try {
            $id = $this->app->make(MailerService::class)->sendSystemMail($this->user()->email, brand() . ' SMTP test', "This is a test email from " . brand() . ".\n\nIf you received it, SMTP is configured correctly.");
            $this->flash('success', 'Test email accepted by the mail server (Message-ID ' . $id . ').');
            $this->app->make(AuditLogger::class)->log('settings.smtp_test', $this->user()->id, null, ['ok' => true]);
        } catch (\Throwable $e) {
            $this->flash('danger', 'SMTP test failed: ' . \BharatSeo\Core\Logger::redactString($e->getMessage()));
            $this->app->make(AuditLogger::class)->log('settings.smtp_test', $this->user()->id, null, ['ok' => false, 'error' => $e->getMessage()]);
        }
        return $this->redirect('/settings');
    }

    public function testAi(): Response
    {
        $client = $this->app->make(LocalModelClient::class);
        if (!$client->isAvailable()) {
            $this->flash('warning', 'Enable the Local AI module and set the endpoint and model first.');
            return $this->redirect('/settings');
        }
        try {
            $out = $client->rewrite(['site_name' => 'example.org', 'page_title' => 'Gardening resources', 'our_brand' => 'Example Brand'], ['subject' => 'A resource suggestion', 'body' => "Hello example.org team,\n\nWe publish a guide that may help your readers.\n\nBest regards,\nExample"]);
            $this->flash('success', 'Model responded. Subject: ' . mb_substr($out['subject'], 0, 120));
        } catch (\Throwable $e) {
            $this->flash('danger', 'Model test failed: ' . $e->getMessage());
        }
        return $this->redirect('/settings');
    }
}
