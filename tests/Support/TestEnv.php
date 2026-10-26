<?php
declare(strict_types=1);

namespace Flatmates\Tests\Support;

use Flatmates\Core\App;
use Flatmates\Core\Database;
use Flatmates\Security\Auth;
use Flatmates\Security\User;
use Flatmates\Services\Installer;
use Flatmates\Services\Mail\MailMessage;
use Flatmates\Services\Mail\MailTransport;
use Flatmates\Services\Mailer;
use Flatmates\Tests\SkipTest;

/** Disposable database + temp storage + fixtures. */
final class TestEnv
{
    public static ?string $storage = null;

    public static function dbConfig(?string $name = null): array
    {
        return [
            'host' => getenv('FM_TEST_DB_HOST') ?: '127.0.0.1', 'port' => (int)(getenv('FM_TEST_DB_PORT') ?: 3306), 'socket' => '',
            'name' => $name ?? (getenv('FM_TEST_DB_NAME') ?: 'fm_test'),
            'user' => getenv('FM_TEST_DB_USER') ?: 'bseo', 'pass' => getenv('FM_TEST_DB_PASS') !== false ? (string)getenv('FM_TEST_DB_PASS') : 'bseo_pw', 'charset' => 'utf8mb4',
        ];
    }

    public static function config(?string $dbName = null): array
    {
        self::$storage ??= sys_get_temp_dir() . '/fm-test-' . getmypid();
        foreach (Installer::WRITABLE_DIRS as $d) {
            @mkdir(self::$storage . '/' . $d, 0777, true);
        }
        return [
            'app' => ['name' => 'Student Flatmate Finder', 'url' => 'http://127.0.0.1:8099', 'env' => 'testing', 'debug' => true, 'timezone' => 'Asia/Kolkata', 'key' => 'base64:' . base64_encode(str_repeat('k', 32))],
            'db' => self::dbConfig($dbName),
            'paths' => ['storage' => self::$storage],
            'cron' => ['web_trigger_enabled' => false, 'web_trigger_secret' => str_repeat('a', 64)],
        ];
    }

    public static function freshApp(?string $dbName = null): App
    {
        $cfg = self::config($dbName);
        $db = $cfg['db'];
        try {
            $pdo = new \PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3]);
        } catch (\PDOException $e) {
            throw new SkipTest('Test database unavailable (' . $e->getMessage() . '). Set FM_TEST_DB_* env vars.');
        }
        if (!preg_match('/^[a-z0-9_]*test[a-z0-9_]*$/i', $db['name'])) {
            throw new SkipTest('Refusing to reset a database whose name does not contain "test".');
        }
        $pdo->exec("DROP DATABASE IF EXISTS `{$db['name']}`");
        $pdo->exec("CREATE DATABASE `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $app = App::boot(true, $cfg);
        Installer::runSql($app->db(), APP_ROOT . '/database/schema.sql');
        Installer::runSql($app->db(), APP_ROOT . '/database/seed_locations.sql');
        $app->make(Mailer::class)->setTransport(self::mailbox());
        return $app;
    }

    public static function mailbox(): MemoryTransport
    {
        static $box = null;
        return $box ??= new MemoryTransport();
    }

    public static function db(): Database
    {
        return App::get()->db();
    }

    public static function user(int $id): User
    {
        return User::fromRow(self::db()->fetch('SELECT * FROM users WHERE id = ?', [$id]));
    }

    public static function makeUser(string $email, string $role = 'student', array $extra = []): int
    {
        return self::db()->insert('users', $extra + ['name' => ucfirst((string)strtok($email, '@')) . ' Test', 'email' => $email, 'password_hash' => Auth::hash('Test#Passw0rd9'), 'role' => $role, 'status' => 'active', 'email_verified_at' => gmdate('Y-m-d H:i:s'), 'date_of_birth' => '2004-05-01', 'age_confirmed' => 1]);
    }

    /** Active, onboarded, discoverable student in Noida with sensible defaults. */
    public static function student(string $email, array $profile = []): int
    {
        $id = self::makeUser($email);
        self::db()->insert('profiles', $profile + [
            'user_id' => $id, 'display_name' => ucfirst((string)strtok($email, '@')), 'city_id' => 1, 'college_id' => 1, 'locality' => 'Sector 62',
            'accommodation_status' => 'looking_room', 'flatmate_gender_pref' => 'any', 'gender' => 'female', 'budget_min' => 6000, 'budget_max' => 10000,
            'move_in_date' => gmdate('Y-m-d', time() + 86400 * 20), 'cleanliness' => 4, 'sleep_schedule' => 'normal', 'smoking' => 'never', 'smoking_ok' => 0,
            'alcohol' => 'never', 'diet' => 'veg', 'study_habits' => 'quiet', 'social_level' => 'balanced', 'guests' => 'sometimes', 'room_type' => 'any',
            'bio' => 'Hi there', 'course' => 'B.Tech', 'languages' => 'Hindi,English', 'completeness' => 90, 'onboarding_step' => 5, 'onboarded_at' => gmdate('Y-m-d H:i:s'),
            'field_visibility' => json_encode(['gender' => false, 'age' => false]),
        ]);
        return $id;
    }

    public static function lastCode(): ?string
    {
        $m = self::mailbox()->last();
        return $m && preg_match('/Your code is: (\d{6})/', $m->body, $mm) ? $mm[1] : null;
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

    /** Small valid JPEG for upload tests. */
    public static function jpeg(int $w = 200, int $h = 160): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 40, 120, 200));
        $f = tempnam(sys_get_temp_dir(), 'fmimg');
        imagejpeg($im, $f, 80);
        return $f;
    }

    public static function upload(string $path, string $name = 'photo.jpg'): array
    {
        return ['name' => $name, 'type' => 'image/jpeg', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
    }
}

final class MemoryTransport implements MailTransport
{
    /** @var MailMessage[] */
    public array $sent = [];
    public bool $fail = false;

    public function send(MailMessage $message): string
    {
        if ($this->fail) {
            throw new \Flatmates\Services\Mail\MailException('SMTP down');
        }
        $this->sent[] = $message;
        return 'mem-' . count($this->sent);
    }

    public function last(): ?MailMessage
    {
        return $this->sent ? end($this->sent) : null;
    }
}
