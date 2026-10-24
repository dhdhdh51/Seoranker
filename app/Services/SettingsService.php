<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;

/**
 * Application settings stored in application_settings. Secrets are encrypted
 * at rest and never returned to views (use has() / masked display instead).
 */
final class SettingsService
{
    /** Default values and metadata. 'secret' => encrypted at rest. */
    public const DEFINITIONS = [
        // General
        'app.timezone' => ['default' => 'Asia/Kolkata', 'type' => 'timezone', 'label' => 'Timezone'],
        'app.agency_name' => ['default' => 'NoidaRankers', 'type' => 'string', 'label' => 'Agency name'],
        // Modules (Super Admin can enable/disable individually)
        'module.discovery' => ['default' => true, 'type' => 'bool', 'label' => 'Opportunity discovery'],
        'module.outreach' => ['default' => true, 'type' => 'bool', 'label' => 'Outreach'],
        'module.monitoring' => ['default' => true, 'type' => 'bool', 'label' => 'Backlink monitoring'],
        'module.reports' => ['default' => true, 'type' => 'bool', 'label' => 'Reports & exports'],
        'module.audit' => ['default' => true, 'type' => 'bool', 'label' => 'Site audit tool'],
        'module.search_api' => ['default' => false, 'type' => 'bool', 'label' => 'Search-provider API discovery'],
        'module.local_ai' => ['default' => false, 'type' => 'bool', 'label' => 'Local AI model drafting'],
        // Queue / cron
        'queue.paused' => ['default' => false, 'type' => 'bool', 'label' => 'Pause all queued jobs'],
        'queue.max_runtime_seconds' => ['default' => 50, 'type' => 'int', 'label' => 'Worker max runtime (s)', 'min' => 10, 'max' => 300],
        'queue.max_jobs_per_run' => ['default' => 40, 'type' => 'int', 'label' => 'Max jobs per worker run', 'min' => 1, 'max' => 500],
        'queue.default_max_attempts' => ['default' => 5, 'type' => 'int', 'label' => 'Default max attempts', 'min' => 1, 'max' => 10],
        // Crawler
        'crawler.user_agent' => ['default' => '', 'type' => 'string', 'label' => 'Crawler user-agent (blank = default)'],
        'crawler.connect_timeout' => ['default' => 5, 'type' => 'int', 'label' => 'Connect timeout (s)', 'min' => 1, 'max' => 30],
        'crawler.timeout' => ['default' => 15, 'type' => 'int', 'label' => 'Total request timeout (s)', 'min' => 3, 'max' => 60],
        'crawler.max_bytes' => ['default' => 2000000, 'type' => 'int', 'label' => 'Max response size (bytes)', 'min' => 50000, 'max' => 10000000],
        'crawler.max_redirects' => ['default' => 5, 'type' => 'int', 'label' => 'Max redirects', 'min' => 0, 'max' => 10],
        'crawler.domain_interval_ms' => ['default' => 3000, 'type' => 'int', 'label' => 'Min interval per domain (ms)', 'min' => 500, 'max' => 60000],
        'crawler.retries' => ['default' => 2, 'type' => 'int', 'label' => 'In-request retries', 'min' => 0, 'max' => 3],
        'crawler.daily_fetch_limit' => ['default' => 2000, 'type' => 'int', 'label' => 'Max page fetches per day', 'min' => 10, 'max' => 100000],
        // Outreach
        'outreach.daily_limit' => ['default' => 30, 'type' => 'int', 'label' => 'Daily email limit (all projects)', 'min' => 0, 'max' => 2000],
        'outreach.per_project_daily_limit' => ['default' => 15, 'type' => 'int', 'label' => 'Daily email limit per project', 'min' => 0, 'max' => 1000],
        'outreach.domain_cooldown_days' => ['default' => 14, 'type' => 'int', 'label' => 'Per-domain cooldown (days)', 'min' => 1, 'max' => 365],
        'outreach.max_send_attempts' => ['default' => 3, 'type' => 'int', 'label' => 'Max SMTP send attempts', 'min' => 1, 'max' => 10],
        'outreach.auto_follow_up_drafts' => ['default' => true, 'type' => 'bool', 'label' => 'Auto-generate follow-up drafts (still require approval)'],
        'outreach.physical_address' => ['default' => '', 'type' => 'string', 'label' => 'Sender postal address (footer)'],
        // SMTP
        'smtp.driver' => ['default' => 'smtp', 'type' => 'choice', 'choices' => ['smtp', 'log'], 'label' => 'Mail driver'],
        'smtp.host' => ['default' => '', 'type' => 'string', 'label' => 'SMTP host'],
        'smtp.port' => ['default' => 587, 'type' => 'int', 'label' => 'SMTP port', 'min' => 1, 'max' => 65535],
        'smtp.encryption' => ['default' => 'tls', 'type' => 'choice', 'choices' => ['tls', 'ssl', 'none'], 'label' => 'Encryption'],
        'smtp.username' => ['default' => '', 'type' => 'string', 'label' => 'SMTP username'],
        'smtp.password' => ['default' => '', 'type' => 'secret', 'label' => 'SMTP password'],
        'smtp.from_email' => ['default' => '', 'type' => 'email', 'label' => 'From email'],
        'smtp.from_name' => ['default' => 'NoidaRankers', 'type' => 'string', 'label' => 'From name'],
        'smtp.timeout' => ['default' => 15, 'type' => 'int', 'label' => 'SMTP timeout (s)', 'min' => 3, 'max' => 60],
        // Monitoring
        'monitor.interval_days' => ['default' => 7, 'type' => 'int', 'label' => 'Re-check active links every (days)', 'min' => 1, 'max' => 90],
        'monitor.retry_hours' => ['default' => 6, 'type' => 'int', 'label' => 'Re-check after a negative/temporary result (hours)', 'min' => 1, 'max' => 72],
        'monitor.confirmations' => ['default' => 2, 'type' => 'int', 'label' => 'Consecutive negative checks before changing status', 'min' => 2, 'max' => 10],
        'monitor.failure_threshold' => ['default' => 3, 'type' => 'int', 'label' => 'Consecutive temporary errors before marking broken', 'min' => 2, 'max' => 20],
        'monitor.max_checks_per_run' => ['default' => 25, 'type' => 'int', 'label' => 'Max backlink checks enqueued per cron run', 'min' => 1, 'max' => 1000],
        // Discovery / search
        'search.provider' => ['default' => 'none', 'type' => 'choice', 'choices' => ['none', 'google_cse', 'brave', 'searxng'], 'label' => 'Search provider'],
        'search.api_key' => ['default' => '', 'type' => 'secret', 'label' => 'Search API key'],
        'search.cse_id' => ['default' => '', 'type' => 'string', 'label' => 'Google Programmable Search engine ID (cx) — legacy API, existing customers only, discontinued 1 Jan 2027'],
        'search.searxng_url' => ['default' => '', 'type' => 'url', 'label' => 'SearXNG instance base URL (authorized)'],
        'search.daily_quota' => ['default' => 90, 'type' => 'int', 'label' => 'Search API daily query quota', 'min' => 0, 'max' => 10000],
        'discovery.max_results_per_query' => ['default' => 10, 'type' => 'int', 'label' => 'Results per query', 'min' => 1, 'max' => 50],
        'discovery.public_page_max_links' => ['default' => 100, 'type' => 'int', 'label' => 'Max candidate links extracted from a public page', 'min' => 5, 'max' => 500],
        'discovery.blocklist' => ['default' => '', 'type' => 'text', 'label' => 'Global unsafe/blocked domains (one per line)'],
        'safebrowsing.api_key' => ['default' => '', 'type' => 'secret', 'label' => 'Google Safe Browsing API key (optional)'],
        // Local AI
        'ai.endpoint' => ['default' => '', 'type' => 'url', 'label' => 'Local model endpoint (OpenAI-compatible /v1/chat/completions)'],
        'ai.model' => ['default' => '', 'type' => 'string', 'label' => 'Model name'],
        'ai.api_key' => ['default' => '', 'type' => 'secret', 'label' => 'Endpoint API key (optional)'],
        'ai.timeout' => ['default' => 30, 'type' => 'int', 'label' => 'Model timeout (s)', 'min' => 5, 'max' => 120],
        // Security
        'security.session_idle_minutes' => ['default' => 60, 'type' => 'int', 'label' => 'Session idle timeout (minutes)', 'min' => 5, 'max' => 1440],
        'security.upload_max_mb' => ['default' => 5, 'type' => 'int', 'label' => 'Max CSV upload size (MB)', 'min' => 1, 'max' => 50],
        // Site audit
        'audit.interval_ms' => ['default' => 1000, 'type' => 'int', 'label' => 'Delay between audit requests to your own site (ms)', 'min' => 500, 'max' => 10000],
        // Website single sign-on (main company website admin)
        'sso.website_enabled' => ['default' => false, 'type' => 'bool', 'label' => 'Allow signing in to the company website admin with this account'],
        'sso.website_url' => ['default' => '', 'type' => 'url', 'label' => 'Company website URL (e.g. https://noidarankers.in)'],
        'sso.secret' => ['default' => '', 'type' => 'secret', 'label' => 'Shared SSO secret (same value in the website config.php)'],
        'sso.roles' => ['default' => 'staff', 'type' => 'choice', 'choices' => ['super_admin', 'staff'], 'label' => 'Who may sign in to the website admin (super_admin = admins only, staff = admins + SEO managers)'],
        // Cron
        'cron.expected_interval_minutes' => ['default' => 5, 'type' => 'int', 'label' => 'Expected cron interval (minutes)', 'min' => 1, 'max' => 1440],
    ];

    private Database $db;
    private ?array $cache = null;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    private function load(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->fetchAll('SELECT setting_key, value, is_encrypted FROM application_settings') as $r) {
                $this->cache[$r['setting_key']] = $r;
            }
        }
        return $this->cache;
    }

    public function refresh(): void
    {
        $this->cache = null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $rows = $this->load();
        $def = self::DEFINITIONS[$key] ?? null;
        if (!isset($rows[$key])) {
            return $def['default'] ?? $default;
        }
        $raw = $rows[$key]['value'];
        if ((int)$rows[$key]['is_encrypted'] === 1 && $raw !== null && $raw !== '') {
            try {
                $raw = $this->app->crypto()->decrypt($raw);
            } catch (\Throwable $e) {
                $this->app->logger()->error('Failed to decrypt setting', ['setting' => $key]);
                return $def['default'] ?? $default;
            }
        }
        return self::cast($raw, $def['type'] ?? 'string');
    }

    public function bool(string $key): bool
    {
        return (bool)$this->get($key, false);
    }

    public function int(string $key): int
    {
        return (int)$this->get($key, 0);
    }

    public function has(string $key): bool
    {
        $v = $this->get($key, '');
        return $v !== '' && $v !== null;
    }

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        $def = self::DEFINITIONS[$key] ?? ['type' => 'string'];
        $secret = ($def['type'] ?? '') === 'secret';
        $stored = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        if ($secret && $stored !== '') {
            $stored = $this->app->crypto()->encrypt($stored);
        }
        $this->db->run(
            'INSERT INTO application_settings (setting_key, value, is_encrypted, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), is_encrypted = VALUES(is_encrypted), updated_by = VALUES(updated_by)',
            [$key, $stored, $secret ? 1 : 0, $userId]
        );
        $this->cache = null;
    }

    public static function cast(mixed $raw, string $type): mixed
    {
        return match ($type) {
            'bool' => in_array($raw, ['1', 1, true, 'true', 'on', 'yes'], true),
            'int' => (int)$raw,
            default => (string)$raw,
        };
    }

    /**
     * Validate and normalize a submitted value according to its definition.
     * @return array{0:bool,1:mixed,2:?string} [ok, value, error]
     */
    public static function validate(string $key, mixed $value): array
    {
        $def = self::DEFINITIONS[$key] ?? null;
        if ($def === null) {
            return [false, null, 'Unknown setting.'];
        }
        $label = $def['label'];
        switch ($def['type']) {
            case 'bool':
                return [true, (bool)$value, null];
            case 'int':
                if (!is_numeric($value) || (string)(int)$value !== trim((string)$value)) {
                    return [false, null, "$label must be a whole number."];
                }
                $v = (int)$value;
                if (isset($def['min']) && $v < $def['min'] || isset($def['max']) && $v > $def['max']) {
                    return [false, null, "$label must be between {$def['min']} and {$def['max']}."];
                }
                return [true, $v, null];
            case 'choice':
                return in_array($value, $def['choices'], true) ? [true, $value, null] : [false, null, "$label has an invalid value."];
            case 'email':
                $value = trim((string)$value);
                return $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) ? [true, $value, null] : [false, null, "$label must be a valid email address."];
            case 'url':
                $value = trim((string)$value);
                return $value === '' || preg_match('#^https?://[^\s]+$#i', $value) ? [true, $value, null] : [false, null, "$label must be an http(s) URL."];
            case 'timezone':
                return in_array($value, \DateTimeZone::listIdentifiers(), true) ? [true, $value, null] : [false, null, 'Invalid timezone.'];
            case 'text':
                return [true, mb_substr(str_replace("\r", '', (string)$value), 0, 20000), null];
            case 'secret':
            case 'string':
            default:
                $value = trim((string)$value);
                if (preg_match('/[\r\n]/', $value)) {
                    return [false, null, "$label must be a single line."];
                }
                return [true, mb_substr($value, 0, 500), null];
        }
    }

    public function moduleEnabled(string $module): bool
    {
        return $this->bool('module.' . $module);
    }

    /** Project-level settings */
    public function project(int $projectId, string $key, mixed $default = null): mixed
    {
        $v = $this->db->fetchColumn('SELECT value FROM project_settings WHERE project_id = ? AND setting_key = ?', [$projectId, $key]);
        return $v ?? $default;
    }

    public function setProject(int $projectId, string $key, ?string $value): void
    {
        $this->db->run(
            'INSERT INTO project_settings (project_id, setting_key, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$projectId, $key, $value]
        );
    }
}
