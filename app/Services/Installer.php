<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\Config;
use Flatmates\Core\Database;
use Flatmates\Security\Auth;
use Flatmates\Security\Crypto;

/** Installation shared by public/install.php and bin/install.php. Locks itself after success. */
final class Installer
{
    public const REQUIRED_EXTENSIONS = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json', 'fileinfo', 'session', 'gd'];
    public const WRITABLE_DIRS = ['logs', 'sessions', 'uploads', 'uploads/photos', 'uploads/listings', 'private', 'private/verifications', 'mail'];

    public static function lockFile(string $storage): string
    {
        return rtrim($storage, '/') . '/installed.lock';
    }

    public static function isLocked(string $storage): bool
    {
        return is_file(self::lockFile($storage)) || Config::locate() !== null;
    }

    public static function splitSql(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        return array_values(array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', $sql) ?: []), static fn($s) => $s !== ''));
    }

    public static function runSql(Database $db, string $file): void
    {
        foreach (self::splitSql((string)file_get_contents($file)) as $stmt) {
            $db->pdo()->exec($stmt);
        }
    }

    /** @return array<int,array{label:string, ok:bool, detail:string, required:bool}> */
    public static function checks(string $storage): array
    {
        $c = [['label' => 'PHP ≥ 8.2', 'ok' => PHP_VERSION_ID >= 80200, 'detail' => PHP_VERSION, 'required' => true]];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $c[] = ['label' => "Extension $ext", 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'loaded' : 'missing', 'required' => true];
        }
        foreach (self::WRITABLE_DIRS as $d) {
            $p = rtrim($storage, '/') . '/' . $d;
            if (!is_dir($p)) {
                @mkdir($p, str_starts_with($d, 'private') ? 0700 : 0750, true);
            }
            $c[] = ['label' => "Writable storage/$d", 'ok' => is_dir($p) && is_writable($p), 'detail' => $p, 'required' => true];
        }
        return $c;
    }

    public static function chooseConfigPath(): string
    {
        $outside = dirname(APP_ROOT) . '/flatmates-private';
        if ((is_dir($outside) || @mkdir($outside, 0750, true)) && is_writable($outside)) {
            return $outside . '/config.php';
        }
        return APP_ROOT . '/config/config.php';
    }

    /**
     * @param array{db_host:string,db_port:int,db_name:string,db_user:string,db_pass:string,app_url:string,admin_name:string,admin_email:string,admin_password:string,timezone?:string,seed_locations?:bool} $in
     * @return array{config_path:string}
     */
    public static function install(array $in, ?string $configPath = null, ?string $storage = null): array
    {
        $storage ??= APP_ROOT . '/storage';
        if (self::isLocked($storage)) {
            throw new \RuntimeException('Already installed. The installer is locked.');
        }
        $problems = [];
        foreach (self::checks($storage) as $c) {
            if ($c['required'] && !$c['ok']) {
                $problems[] = $c['label'] . ' (' . $c['detail'] . ')';
            }
        }
        if (!filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL) || mb_strlen(trim($in['admin_name'])) < 2) {
            $problems[] = 'Super Admin name and a valid email are required.';
        }
        if ($p = Auth::passwordProblem($in['admin_password'], $in['admin_email'])) {
            $problems[] = $p;
        }
        if (!preg_match('#^https?://[^\s/]+$#', rtrim($in['app_url'], '/'))) {
            $problems[] = 'Site URL must look like https://flatmates.example.com (no path).';
        }
        $tz = $in['timezone'] ?? 'Asia/Kolkata';
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $problems[] = 'Invalid timezone.';
        }
        if ($problems) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }
        $dbCfg = ['host' => $in['db_host'], 'port' => (int)$in['db_port'], 'socket' => '', 'name' => $in['db_name'], 'user' => $in['db_user'], 'pass' => $in['db_pass'], 'charset' => 'utf8mb4'];
        try {
            $db = Database::connect($dbCfg);
        } catch (\PDOException $e) {
            throw new \InvalidArgumentException('Database connection failed: ' . preg_replace('/\(using password: \w+\)/', '', $e->getMessage()));
        }
        if ((int)$db->fetchColumn("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'") > 0
            && (int)$db->fetchColumn('SELECT COUNT(*) FROM users') > 0) {
            throw new \InvalidArgumentException('The database already contains an installation. Use an empty database.');
        }
        self::runSql($db, APP_ROOT . '/database/schema.sql');
        if ($in['seed_locations'] ?? true) {
            self::runSql($db, APP_ROOT . '/database/seed_locations.sql');
        }
        $db->insert('users', ['name' => trim($in['admin_name']), 'email' => strtolower(trim($in['admin_email'])), 'password_hash' => Auth::hash($in['admin_password']), 'role' => 'super_admin', 'status' => 'active', 'email_verified_at' => gmdate('Y-m-d H:i:s'), 'age_confirmed' => 1, 'password_changed_at' => gmdate('Y-m-d H:i:s')]);
        $db->insert('audit_logs', ['action' => 'system.installed', 'details' => json_encode(['version' => APP_VERSION]), 'ip_address' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45)]);
        $config = [
            'app' => ['name' => 'Student Flatmate Finder', 'url' => rtrim($in['app_url'], '/'), 'env' => 'production', 'debug' => false, 'timezone' => $tz, 'key' => Crypto::generateKey(), 'demo_mode' => false],
            'db' => $dbCfg,
            'paths' => ['storage' => $storage],
            'session' => ['name' => 'SFFSESSID'],
            'cron' => ['web_trigger_enabled' => false, 'web_trigger_secret' => bin2hex(random_bytes(32))],
            'security' => ['trusted_proxies' => []],
        ];
        $configPath ??= self::chooseConfigPath();
        if (!is_dir(dirname($configPath)) && !@mkdir(dirname($configPath), 0750, true)) {
            throw new \RuntimeException('Cannot create ' . dirname($configPath));
        }
        if (file_put_contents($configPath, Config::export($config), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write ' . $configPath);
        }
        @chmod($configPath, 0640);
        file_put_contents(self::lockFile($storage), 'installed ' . gmdate('c') . ' v' . APP_VERSION . "\n");
        return ['config_path' => $configPath];
    }
}
