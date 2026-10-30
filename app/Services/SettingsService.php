<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\Database;

/** Admin-configurable settings; secrets are AES-256-GCM encrypted at rest and never shown. */
final class SettingsService
{
    public const DEFINITIONS = [
        // Branding
        'brand.name' => ['default' => 'Student Flatmate Finder', 'type' => 'string', 'label' => 'Brand name', 'group' => 'Branding'],
        'brand.tagline' => ['default' => 'Find verified student flatmates and rooms near your college.', 'type' => 'string', 'label' => 'Tagline', 'group' => 'Branding'],
        'brand.primary_color' => ['default' => '#3478f6', 'type' => 'color', 'label' => 'Primary colour (trust blue)', 'group' => 'Branding'],
        'brand.accent_color' => ['default' => '#142b4a', 'type' => 'color', 'label' => 'Secondary colour (deep navy)', 'group' => 'Branding'],
        'brand.logo_text' => ['default' => 'SF', 'type' => 'string', 'label' => 'Logo initials', 'group' => 'Branding'],
        'brand.support_email' => ['default' => 'flatmates@noidarankers.in', 'type' => 'email', 'label' => 'Support email', 'group' => 'Branding'],
        'brand.company' => ['default' => 'NoidaRankers', 'type' => 'string', 'label' => 'Operated by', 'group' => 'Branding'],
        'brand.company_url' => ['default' => 'https://noidarankers.in', 'type' => 'url', 'label' => 'Company website', 'group' => 'Branding'],
        // SEO
        'seo.home_title' => ['default' => 'Find Student Flatmates & Rooms Near Your College in India', 'type' => 'string', 'label' => 'Home page title', 'group' => 'SEO'],
        'seo.home_description' => ['default' => 'Match with compatible, verified student flatmates and find flats, shared rooms and vacant beds near your college in Noida, Delhi, Bengaluru, Pune and more.', 'type' => 'text', 'label' => 'Home meta description', 'group' => 'SEO'],
        'seo.google_verification' => ['default' => '', 'type' => 'string', 'label' => 'Google Search Console verification code', 'group' => 'SEO'],
        // Rules & limits
        'rules.min_age' => ['default' => 18, 'type' => 'int', 'label' => 'Minimum age', 'min' => 16, 'max' => 30, 'group' => 'Rules & limits'],
        'rules.listing_review' => ['default' => true, 'type' => 'bool', 'label' => 'New listings need moderator approval', 'group' => 'Rules & limits'],
        'rules.verified_to_message' => ['default' => false, 'type' => 'bool', 'label' => 'Only verified students can send messages', 'group' => 'Rules & limits'],
        'rules.daily_likes' => ['default' => 60, 'type' => 'int', 'label' => 'Swipes per day', 'min' => 5, 'max' => 1000, 'group' => 'Rules & limits'],
        'rules.messages_per_hour' => ['default' => 60, 'type' => 'int', 'label' => 'Messages per hour per user', 'min' => 5, 'max' => 1000, 'group' => 'Rules & limits'],
        'rules.listings_per_user' => ['default' => 5, 'type' => 'int', 'label' => 'Active listings per student', 'min' => 1, 'max' => 50, 'group' => 'Rules & limits'],
        'rules.enquiries_per_day' => ['default' => 20, 'type' => 'int', 'label' => 'Enquiries per day per student', 'min' => 1, 'max' => 500, 'group' => 'Rules & limits'],
        'rules.auto_hide_reports' => ['default' => 3, 'type' => 'int', 'label' => 'Hide listing automatically after N open reports', 'min' => 2, 'max' => 50, 'group' => 'Rules & limits'],
        'rules.min_completeness' => ['default' => 60, 'type' => 'int', 'label' => 'Profile completeness needed to appear in discovery (%)', 'min' => 0, 'max' => 100, 'group' => 'Rules & limits'],
        'security.session_idle_minutes' => ['default' => 120, 'type' => 'int', 'label' => 'Sign out after inactivity (minutes)', 'min' => 15, 'max' => 1440, 'group' => 'Rules & limits'],
        'system.maintenance' => ['default' => false, 'type' => 'bool', 'label' => 'Maintenance mode (only staff can sign in)', 'group' => 'Rules & limits'],
        // SMTP
        'smtp.driver' => ['default' => 'smtp', 'type' => 'choice', 'choices' => ['smtp', 'log'], 'label' => 'Mail driver', 'group' => 'Email (SMTP)'],
        'smtp.host' => ['default' => '', 'type' => 'string', 'label' => 'SMTP host', 'group' => 'Email (SMTP)'],
        'smtp.port' => ['default' => 587, 'type' => 'int', 'label' => 'SMTP port', 'min' => 1, 'max' => 65535, 'group' => 'Email (SMTP)'],
        'smtp.encryption' => ['default' => 'tls', 'type' => 'choice', 'choices' => ['tls', 'ssl', 'none'], 'label' => 'Encryption', 'group' => 'Email (SMTP)'],
        'smtp.username' => ['default' => '', 'type' => 'string', 'label' => 'SMTP username', 'group' => 'Email (SMTP)'],
        'smtp.password' => ['default' => '', 'type' => 'secret', 'label' => 'SMTP password', 'group' => 'Email (SMTP)'],
        'smtp.from_email' => ['default' => '', 'type' => 'email', 'label' => 'From email', 'group' => 'Email (SMTP)'],
        'smtp.from_name' => ['default' => 'Student Flatmate Finder', 'type' => 'string', 'label' => 'From name', 'group' => 'Email (SMTP)'],
        'mail.footer' => ['default' => 'You are receiving this email because you have an account on Student Flatmate Finder.', 'type' => 'text', 'label' => 'Email footer text', 'group' => 'Email (SMTP)'],
        // Android app distribution (values come from the published GitHub Release — never invent them)
        'android.download_url' => ['default' => '', 'type' => 'https_url', 'label' => 'APK download URL (the published release asset, e.g. https://github.com/OWNER/REPO/releases/download/TAG/app.apk)', 'group' => 'Android app'],
        'android.releases_url' => ['default' => '', 'type' => 'https_url', 'label' => 'Official releases page (fallback link)', 'group' => 'Android app'],
        'android.version' => ['default' => '', 'type' => 'version', 'label' => 'Current app version (e.g. 1.0.0)', 'group' => 'Android app'],
        'android.release_date' => ['default' => '', 'type' => 'date', 'label' => 'Release date (YYYY-MM-DD)', 'group' => 'Android app'],
        'android.sha256' => ['default' => '', 'type' => 'sha256', 'label' => 'APK SHA-256 checksum (64 hex characters)', 'group' => 'Android app'],
        'android.package' => ['default' => 'in.noidarankers.flatmates', 'type' => 'package', 'label' => 'Android application ID (for App Links)', 'group' => 'Android app'],
        'android.cert_sha256' => ['default' => '', 'type' => 'fingerprint', 'label' => 'Release signing certificate SHA-256 fingerprint (AA:BB:… — enables /.well-known/assetlinks.json)', 'group' => 'Android app'],
        // SSO from the NoidaRankers platform
        'sso.secret' => ['default' => '', 'type' => 'secret', 'label' => 'NoidaRankers platform SSO secret (staff sign-in)', 'group' => 'NoidaRankers connection'],
        'sso.platform_url' => ['default' => 'https://seo.bharatseo.site', 'type' => 'url', 'label' => 'NoidaRankers platform URL', 'group' => 'NoidaRankers connection'],
    ];

    private Database $db;
    private ?array $cache = null;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    public function refresh(): void
    {
        $this->cache = null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->cache ??= array_column($this->db->fetchAll('SELECT setting_key, value, is_encrypted FROM application_settings'), null, 'setting_key');
        $def = self::DEFINITIONS[$key] ?? null;
        if (!isset($this->cache[$key])) {
            return $def['default'] ?? $default;
        }
        $raw = $this->cache[$key]['value'];
        if ((int)$this->cache[$key]['is_encrypted'] === 1 && $raw) {
            try {
                $raw = $this->app->crypto()->decrypt($raw);
            } catch (\Throwable) {
                return $def['default'] ?? $default;
            }
        }
        return match ($def['type'] ?? 'string') {
            'bool' => in_array($raw, ['1', 'true', 'on'], true),
            'int' => (int)$raw,
            default => (string)$raw,
        };
    }

    public function bool(string $key): bool
    {
        return (bool)$this->get($key);
    }

    public function has(string $key): bool
    {
        return (string)$this->get($key, '') !== '';
    }

    public function set(string $key, mixed $value): void
    {
        $secret = (self::DEFINITIONS[$key]['type'] ?? '') === 'secret';
        $v = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        if ($secret && $v !== '') {
            $v = $this->app->crypto()->encrypt($v);
        }
        $this->db->run('INSERT INTO application_settings (setting_key, value, is_encrypted) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), is_encrypted = VALUES(is_encrypted)', [$key, $v, $secret ? 1 : 0]);
        $this->cache = null;
    }

    /** @return array{0:bool,1:mixed,2:?string} */
    public static function validate(string $key, mixed $value): array
    {
        $d = self::DEFINITIONS[$key] ?? null;
        if (!$d) {
            return [false, null, 'Unknown setting'];
        }
        $v = is_scalar($value) ? trim((string)$value) : '';
        return match ($d['type']) {
            'bool' => [true, in_array($v, ['1', 'on', 'true'], true), null],
            'int' => preg_match('/^\d+$/', $v) && (int)$v >= ($d['min'] ?? 0) && (int)$v <= ($d['max'] ?? PHP_INT_MAX) ? [true, (int)$v, null] : [false, null, $d['label'] . " must be a number between {$d['min']} and {$d['max']}."],
            'choice' => in_array($v, $d['choices'], true) ? [true, $v, null] : [false, null, $d['label'] . ' is invalid.'],
            'email' => $v === '' || filter_var($v, FILTER_VALIDATE_EMAIL) ? [true, $v, null] : [false, null, $d['label'] . ' must be an email address.'],
            'url' => $v === '' || preg_match('#^https?://\S+$#', $v) ? [true, rtrim($v, '/'), null] : [false, null, $d['label'] . ' must be a URL.'],
            'https_url' => $v === '' || (preg_match('#^https://[^\s/]+(/\S*)?$#', $v) && filter_var($v, FILTER_VALIDATE_URL)) ? [true, $v, null] : [false, null, $d['label'] . ' must be an https:// URL.'],
            'version' => $v === '' || preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,4}$/', $v) ? [true, $v, null] : [false, null, 'Version must look like 1.0.0.'],
            'date' => $v === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? [true, $v, null] : [false, null, 'Date must look like 2026-10-26.'],
            'sha256' => $v === '' || preg_match('/^[a-f0-9]{64}$/i', $v) ? [true, strtolower($v), null] : [false, null, 'Checksum must be 64 hexadecimal characters.'],
            'package' => preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $v) ? [true, $v, null] : [false, null, 'Application ID must look like in.example.app.'],
            'fingerprint' => $v === '' || preg_match('/^([A-F0-9]{2}:){31}[A-F0-9]{2}$/i', $v) ? [true, strtoupper($v), null] : [false, null, 'Fingerprint must be 32 colon-separated hex pairs.'],
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? [true, strtolower($v), null] : [false, null, $d['label'] . ' must look like #2f6fed.'],
            'text' => [true, mb_substr(str_replace("\r", '', $v), 0, 2000), null],
            default => preg_match('/[\r\n]/', $v) ? [false, null, $d['label'] . ' must be one line.'] : [true, mb_substr($v, 0, 300), null],
        };
    }
}
