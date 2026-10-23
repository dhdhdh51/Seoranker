<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Support;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Security\Auth;
use BharatSeo\Security\User;
use BharatSeo\Services\Migrator;
use BharatSeo\Tests\SkipTest;

/** Test environment: disposable database + temp storage + fixture data. */
final class TestEnv
{
    public static ?string $storage = null;

    public static function dbConfig(?string $name = null): array
    {
        return [
            'host' => getenv('BSEO_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('BSEO_TEST_DB_PORT') ?: 3306),
            'socket' => '',
            'name' => $name ?? (getenv('BSEO_TEST_DB_NAME') ?: 'bseo_test'),
            'user' => getenv('BSEO_TEST_DB_USER') ?: 'bseo',
            'pass' => getenv('BSEO_TEST_DB_PASS') !== false ? (string)getenv('BSEO_TEST_DB_PASS') : 'bseo_pw',
            'charset' => 'utf8mb4',
        ];
    }

    public static function config(?string $dbName = null): array
    {
        self::$storage ??= sys_get_temp_dir() . '/bseo-test-' . getmypid();
        foreach (['logs', 'uploads/imports', 'exports', 'sessions', 'locks', 'mail', 'cache', 'backups'] as $d) {
            @mkdir(self::$storage . '/' . $d, 0777, true);
        }
        return [
            'app' => ['name' => 'Bharat SEO', 'url' => 'http://127.0.0.1:8099', 'env' => 'testing', 'debug' => true, 'timezone' => 'Asia/Kolkata', 'key' => 'base64:' . base64_encode(str_repeat('k', 32)), 'pretty_urls' => true],
            'db' => self::dbConfig($dbName),
            'paths' => ['storage' => self::$storage],
            'cron' => ['web_trigger_enabled' => false, 'web_trigger_secret' => str_repeat('a', 64)],
        ];
    }

    /** Recreate the test database from schema.sql and boot the app against it. */
    public static function freshApp(?string $dbName = null): App
    {
        $cfg = self::config($dbName);
        $db = $cfg['db'];
        try {
            $pdo = new \PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3]);
        } catch (\PDOException $e) {
            throw new SkipTest('Test database unavailable (' . $e->getMessage() . '). Set BSEO_TEST_DB_* env vars.');
        }
        if (!preg_match('/^[a-z0-9_]*test[a-z0-9_]*$/i', $db['name'])) {
            throw new SkipTest('Refusing to reset a database whose name does not contain "test".');
        }
        $pdo->exec("DROP DATABASE IF EXISTS `{$db['name']}`");
        $pdo->exec("CREATE DATABASE `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $app = App::boot(true, $cfg);
        Migrator::runSchema($app->db(), APP_ROOT . '/database/schema.sql');
        return $app;
    }

    public static function app(): App
    {
        return App::get();
    }

    public static function user(int $id): User
    {
        return User::fromRow(App::get()->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]));
    }

    public static function makeUser(string $role, string $email, string $password = 'Test#Passw0rd9'): int
    {
        return App::get()->db()->insert('users', ['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password_hash' => Auth::hashPassword($password), 'role' => $role, 'status' => 'active']);
    }

    /**
     * Standard fixture: 2 clients, 2 projects, admin, manager (member of A only), viewer manager,
     * client users for A (approver) and B.
     * @return array<string,int>
     */
    public static function seed(): array
    {
        $db = App::get()->db();
        $ids = [];
        $ids['admin'] = self::makeUser('super_admin', 'root@agency.test');
        $ids['manager'] = self::makeUser('seo_manager', 'manager@agency.test');
        $ids['manager2'] = self::makeUser('seo_manager', 'other.manager@agency.test');
        $ids['viewer'] = self::makeUser('seo_manager', 'viewer@agency.test');
        $ids['clientA_user'] = self::makeUser('client', 'owner@client-a.test');
        $ids['clientB_user'] = self::makeUser('client', 'owner@client-b.test');
        $ids['clientA'] = $db->insert('clients', ['name' => 'Client A', 'status' => 'active']);
        $ids['clientB'] = $db->insert('clients', ['name' => 'Client B', 'status' => 'active']);
        $ids['projectA'] = $db->insert('projects', ['client_id' => $ids['clientA'], 'name' => 'Green Farm', 'website_url' => 'https://greenfarm.in/', 'domain' => 'greenfarm.in', 'niche' => 'organic farming', 'brand_name' => 'Green Farm', 'preferred_anchors' => "Green Farm\ngreenfarm.in", 'contact_email' => 'hello@greenfarm.in', 'outreach_from_name' => 'Priya from Green Farm', 'approval_policy' => 'manager', 'verification_token' => str_repeat('a', 32)]);
        $ids['projectB'] = $db->insert('projects', ['client_id' => $ids['clientB'], 'name' => 'Tech Shop', 'website_url' => 'https://techshop.example/', 'domain' => 'techshop.example', 'niche' => 'electronics', 'approval_policy' => 'manager', 'verification_token' => str_repeat('b', 32)]);
        $db->insert('project_members', ['project_id' => $ids['projectA'], 'user_id' => $ids['manager'], 'role' => 'manager']);
        $db->insert('project_members', ['project_id' => $ids['projectB'], 'user_id' => $ids['manager2'], 'role' => 'manager']);
        $db->insert('project_members', ['project_id' => $ids['projectA'], 'user_id' => $ids['viewer'], 'role' => 'viewer']);
        $db->insert('client_users', ['client_id' => $ids['clientA'], 'user_id' => $ids['clientA_user'], 'can_approve' => 1, 'can_export' => 1]);
        $db->insert('client_users', ['client_id' => $ids['clientB'], 'user_id' => $ids['clientB_user'], 'can_approve' => 0, 'can_export' => 0]);
        foreach (['organic farming', 'compost', 'soil health'] as $k) {
            $db->insert('project_keywords', ['project_id' => $ids['projectA'], 'keyword' => $k, 'type' => 'keyword']);
        }
        $ids['targetA'] = $db->insert('target_pages', ['project_id' => $ids['projectA'], 'url' => 'https://greenfarm.in/guides/composting/', 'url_hash' => hash('sha256', 'https://greenfarm.in/guides/composting/'), 'title' => 'Home composting guide']);
        return $ids;
    }

    public static function project(int $id): array
    {
        return App::get()->db()->fetch('SELECT * FROM projects WHERE id = ?', [$id]);
    }

    public static function cleanupStorage(): void
    {
        if (self::$storage && is_dir(self::$storage) && str_starts_with(self::$storage, sys_get_temp_dir())) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir(self::$storage);
        }
    }

    public static function db(): Database
    {
        return App::get()->db();
    }
}
