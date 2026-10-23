<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\Config;
use BharatSeo\Core\Database;
use BharatSeo\Security\Auth;
use BharatSeo\Security\Crypto;

/**
 * Installation logic shared by public/install.php and bin/install.php.
 * Never ships default credentials; refuses to run once locked.
 */
final class Installer
{
    public const REQUIRED_EXTENSIONS = ['pdo', 'pdo_mysql', 'curl', 'mbstring', 'openssl', 'json', 'dom', 'libxml', 'fileinfo', 'filter', 'session'];
    public const RECOMMENDED_EXTENSIONS = ['intl' => 'IDN (international domain) normalization', 'zlib' => 'compressed HTTP responses'];
    public const WRITABLE_DIRS = ['logs', 'uploads', 'uploads/imports', 'exports', 'sessions', 'locks', 'mail', 'cache', 'backups'];

    public static function lockFile(string $storage): string
    {
        return rtrim($storage, '/') . '/installed.lock';
    }

    public static function isLocked(string $storage): bool
    {
        return is_file(self::lockFile($storage)) || Config::locate() !== null;
    }

    /** @return array<int,array{label:string, ok:bool, detail:string, required:bool}> */
    public static function environmentChecks(string $storage): array
    {
        $checks = [];
        $checks[] = ['label' => 'PHP version ≥ 8.2', 'ok' => PHP_VERSION_ID >= 80200, 'detail' => PHP_VERSION, 'required' => true];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = ['label' => "Extension: $ext", 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'loaded' : 'missing', 'required' => true];
        }
        foreach (self::RECOMMENDED_EXTENSIONS as $ext => $why) {
            $checks[] = ['label' => "Extension: $ext (recommended)", 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'loaded' : "missing — $why unavailable", 'required' => false];
        }
        foreach (self::WRITABLE_DIRS as $d) {
            $path = rtrim($storage, '/') . '/' . $d;
            if (!is_dir($path)) {
                @mkdir($path, 0750, true);
            }
            $checks[] = ['label' => "Writable: storage/$d", 'ok' => is_dir($path) && is_writable($path), 'detail' => $path, 'required' => true];
        }
        $checks[] = ['label' => 'allow_url_fopen not required', 'ok' => true, 'detail' => 'cURL is used for all HTTP requests', 'required' => false];
        $checks[] = ['label' => 'PHP CLI available for cron', 'ok' => PHP_SAPI === 'cli' || self::cliBinaryGuess() !== null, 'detail' => self::cliBinaryGuess() ?? 'Not detected — use the web-trigger fallback (CRON_SETUP.md)', 'required' => false];
        $checks[] = ['label' => 'HTTPS', 'ok' => PHP_SAPI === 'cli' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'detail' => 'Strongly recommended for secure cookies', 'required' => false];
        return $checks;
    }

    public static function cliBinaryGuess(): ?string
    {
        foreach ([PHP_BINDIR . '/php', '/usr/local/bin/php', '/usr/bin/php', '/opt/cpanel/ea-php82/root/usr/bin/php', '/opt/cpanel/ea-php83/root/usr/bin/php'] as $p) {
            if (@is_executable($p)) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Choose where to write config: outside the application directory when possible
     * (../bharatseo-private/config.php), otherwise config/config.php (protected by .htaccess).
     */
    public static function chooseConfigPath(): string
    {
        $outside = dirname(APP_ROOT) . '/bharatseo-private';
        if ((is_dir($outside) || @mkdir($outside, 0750, true)) && is_writable($outside)) {
            return $outside . '/config.php';
        }
        return APP_ROOT . '/config/config.php';
    }

    /**
     * @param array{db_host:string, db_port:int, db_name:string, db_user:string, db_pass:string, db_socket?:string, app_url:string, timezone:string, admin_name:string, admin_email:string, admin_password:string} $in
     * @return array{config_path:string}
     */
    public static function install(array $in, ?string $configPath = null, ?string $storage = null): array
    {
        $storage ??= APP_ROOT . '/storage';
        if (self::isLocked($storage)) {
            throw new \RuntimeException('Bharat SEO is already installed. The installer is locked.');
        }
        $problems = [];
        foreach (self::environmentChecks($storage) as $c) {
            if ($c['required'] && !$c['ok']) {
                $problems[] = $c['label'] . ' (' . $c['detail'] . ')';
            }
        }
        if (!filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $problems[] = 'A valid Super Admin email is required.';
        }
        if (mb_strlen(trim($in['admin_name'])) < 2) {
            $problems[] = 'Super Admin name is required.';
        }
        $problems = array_merge($problems, Auth::passwordProblems($in['admin_password'], $in['admin_email']));
        if (!in_array($in['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $problems[] = 'Invalid timezone.';
        }
        if ($in['app_url'] !== '' && !preg_match('#^https?://[^\s/]+(/[^\s]*)?$#', $in['app_url'])) {
            $problems[] = 'Application URL must look like https://seo.example.com';
        }
        if ($problems) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        $dbCfg = ['host' => $in['db_host'], 'port' => (int)$in['db_port'], 'name' => $in['db_name'], 'user' => $in['db_user'], 'pass' => $in['db_pass'], 'socket' => $in['db_socket'] ?? '', 'charset' => 'utf8mb4'];
        try {
            $db = Database::connect($dbCfg);
        } catch (\PDOException $e) {
            throw new \InvalidArgumentException('Database connection failed: ' . preg_replace('/\(using password: \w+\)/', '', $e->getMessage()));
        }
        $version = (string)$db->fetchColumn('SELECT VERSION()');
        if ((int)$db->fetchColumn("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'") > 0
            && (int)$db->fetchColumn('SELECT COUNT(*) FROM users') > 0) {
            throw new \InvalidArgumentException('The database already contains a Bharat SEO installation. Use an empty database or follow UPGRADE.md.');
        }
        Migrator::runSchema($db, APP_ROOT . '/database/schema.sql');

        $key = Crypto::generateKey();
        $config = [
            'app' => ['name' => 'Bharat SEO', 'url' => rtrim($in['app_url'], '/'), 'env' => 'production', 'debug' => false, 'timezone' => $in['timezone'], 'key' => $key, 'pretty_urls' => (bool)($in['pretty_urls'] ?? true), 'demo_mode' => false],
            'db' => $dbCfg,
            'paths' => ['storage' => $storage],
            'session' => ['name' => 'BSEOSESSID', 'idle_minutes' => 60, 'absolute_hours' => 12, 'secure_cookie' => 'auto'],
            'cron' => ['web_trigger_enabled' => false, 'web_trigger_secret' => bin2hex(random_bytes(32))],
            'security' => ['trusted_proxies' => []],
        ];
        $db->transaction(function (Database $db) use ($in): void {
            $db->insert('users', [
                'name' => trim($in['admin_name']),
                'email' => strtolower(trim($in['admin_email'])),
                'password_hash' => Auth::hashPassword($in['admin_password']),
                'role' => 'super_admin',
                'status' => 'active',
                'password_changed_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $db->run('INSERT INTO application_settings (setting_key, value, is_encrypted) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['app.timezone', $in['timezone']]);
            $prev = str_repeat('0', 64);
            $now = gmdate('Y-m-d H:i:s');
            $details = json_encode(['version' => APP_VERSION]);
            $db->insert('audit_logs', ['action' => 'system.installed', 'details' => $details, 'ip_address' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45), 'prev_hash' => $prev, 'entry_hash' => AuditLogger::computeHash($prev, $now, 'system.installed', null, null, null, null, $details), 'created_at' => $now]);
        });

        $configPath ??= self::chooseConfigPath();
        $dir = dirname($configPath);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new \RuntimeException('Cannot create config directory ' . $dir);
        }
        if (file_put_contents($configPath, Config::export($config), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write configuration file to ' . $configPath);
        }
        @chmod($configPath, 0640);
        file_put_contents(self::lockFile($storage), 'installed ' . gmdate('c') . ' version ' . APP_VERSION . ' db ' . $version . "\n");
        return ['config_path' => $configPath];
    }
}
