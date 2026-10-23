<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Integration;

use BharatSeo\Core\App;
use BharatSeo\Services\AuditLogger;
use BharatSeo\Services\BackupService;
use BharatSeo\Services\CronService;
use BharatSeo\Services\Installer;
use BharatSeo\Services\ProspectService;
use BharatSeo\Services\ReportService;
use BharatSeo\Services\SettingsService;
use BharatSeo\Tests\Support\TestEnv;
use BharatSeo\Tests\TestCase;

final class SystemTest extends TestCase
{
    private static array $ids;

    public static function setUpBeforeClass(): void
    {
        TestEnv::freshApp();
        self::$ids = TestEnv::seed();
    }

    public function testCronHealthReportsHonestly(): void
    {
        $cron = TestEnv::app()->make(CronService::class);
        $this->assertFalse($cron->isAutomationActive(), 'No runs recorded → automation not active');
        $warnings = implode(' ', array_column($cron->warnings(), 'text'));
        $this->assertContains('Automation is NOT active', $warnings);
        $r = $cron->run('process_queue', 'cli', fn() => [3, 'ok']);
        $this->assertSame('completed', $r['status']);
        $this->assertTrue($cron->isAutomationActive());
        // Stale heartbeat → unhealthy again.
        TestEnv::db()->run("UPDATE cron_runs SET finished_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE script = 'process_queue'");
        $this->assertFalse($cron->isAutomationActive());
        $this->assertContains('appears to be stopped', implode(' ', array_column($cron->warnings(), 'text')));
        // A failing task is recorded as failed, not as success.
        $f = $cron->run('discover', 'cli', function (): array {
            throw new \RuntimeException('boom password=hunter2');
        });
        $this->assertSame('failed', $f['status']);
        $msg = (string)TestEnv::db()->fetchColumn("SELECT message FROM cron_runs WHERE script = 'discover' ORDER BY id DESC LIMIT 1");
        $this->assertNotContains('hunter2', $msg, 'Secrets are redacted from cron logs');
    }

    public function testCronLockPreventsOverlap(): void
    {
        $a = TestEnv::app()->make(CronService::class);
        $b = new CronService(TestEnv::app());
        $this->assertTrue($a->lock('process_queue'));
        $r = $b->run('process_queue', 'cli', fn() => [1, 'should not run']);
        $this->assertSame('skipped', $r['status']);
        $a->unlock();
        $this->assertSame('completed', $b->run('process_queue', 'cli', fn() => [1, 'ran'])['status']);
    }

    public function testCronScriptsRunFromCli(): void
    {
        $php = PHP_BINARY;
        $cfg = TestEnv::config();
        $cfgFile = TestEnv::$storage . '/cli-config.php';
        file_put_contents($cfgFile, \BharatSeo\Core\Config::export($cfg));
        foreach (['process_queue', 'send_approved_outreach', 'check_backlinks', 'discover', 'cleanup'] as $script) {
            $cmd = 'BHARATSEO_CONFIG=' . escapeshellarg($cfgFile) . ' LD_LIBRARY_PATH=' . escapeshellarg((string)getenv('LD_LIBRARY_PATH')) . ' ' . escapeshellarg($php) . ' ' . escapeshellarg(APP_ROOT . '/cron/' . $script . '.php') . ' 2>&1';
            exec($cmd, $out, $code);
            $this->assertSame(0, $code, "$script exited non-zero: " . implode("\n", $out));
            $this->assertContains("$script: completed", implode("\n", $out));
            $out = [];
        }
        $this->assertSame(5, (int)TestEnv::db()->fetchColumn("SELECT COUNT(DISTINCT script) FROM cron_runs WHERE status = 'completed'"));
    }

    public function testAuditChainDetectsTampering(): void
    {
        $audit = TestEnv::app()->make(AuditLogger::class);
        $audit->log('test.one', self::$ids['admin'], self::$ids['projectA'], ['a' => 1, 'password' => 'should-not-appear']);
        $audit->log('test.two', self::$ids['admin']);
        $this->assertTrue($audit->verifyChain()['ok']);
        $details = (string)TestEnv::db()->fetchColumn("SELECT details FROM audit_logs WHERE action = 'test.one'");
        $this->assertNotContains('should-not-appear', $details, 'Secrets are redacted in the audit log');
        TestEnv::db()->run("UPDATE audit_logs SET details = '{\"a\":2}' WHERE action = 'test.one'");
        $v = $audit->verifyChain();
        $this->assertFalse($v['ok']);
        $this->assertSame((int)TestEnv::db()->fetchColumn("SELECT id FROM audit_logs WHERE action = 'test.one'"), $v['broken_at']);
    }

    public function testSecretsAreEncryptedAtRest(): void
    {
        $s = TestEnv::app()->settings();
        $s->set('smtp.password', 'Sup3r-Secret-SMTP');
        $raw = (string)TestEnv::db()->fetchColumn("SELECT value FROM application_settings WHERE setting_key = 'smtp.password'");
        $this->assertNotContains('Sup3r', $raw);
        $this->assertTrue(str_starts_with($raw, 'v1:'));
        $s->refresh();
        $this->assertSame('Sup3r-Secret-SMTP', $s->get('smtp.password'));
        [$ok] = SettingsService::validate('outreach.daily_limit', '-5');
        $this->assertFalse($ok);
        [$ok2, $v2] = SettingsService::validate('outreach.daily_limit', '25');
        $this->assertTrue($ok2);
        $this->assertSame(25, $v2);
        [$ok3] = SettingsService::validate('smtp.host', "evil\r\nheader");
        $this->assertFalse($ok3);
    }

    public function testReportsUseRealRecordsAndExportsAreSafe(): void
    {
        $p = TestEnv::project(self::$ids['projectA']);
        $prospects = TestEnv::app()->make(ProspectService::class);
        $id = (int)$prospects->createFromUrl($p, 'https://report.example/a', ['category' => 'resource_page', 'title' => '=cmd|calc!A1', 'evidence' => '@SUM(1)'], null, null)['id'];
        $prospects->createFromUrl($p, 'https://report.example/b', ['category' => 'guest_post'], null, null);
        TestEnv::db()->update('prospect_pages', ['quality_score' => 85, 'score_band' => 'high', 'status' => 'needs_review'], 'id = ?', [$id]);
        $admin = TestEnv::user(self::$ids['admin']);
        $r = TestEnv::app()->make(ReportService::class)->projectReport($admin, self::$ids['projectA']);
        $this->assertSame(1, $r['awaiting_review']);
        $this->assertSame(1, $r['score_distribution']['80–100']);
        $this->assertSame(2, array_sum(array_column($r['by_category'], 'n')));
        $this->assertSame(0, $r['active_backlinks'], 'Discovered URLs are never counted as backlinks');
        $this->assertTrue(strtotime($r['generated_at'] . ' UTC') >= time() - 5, 'Report carries its generation time');
        $out = fopen('php://memory', 'w+');
        $rows = TestEnv::app()->make(ReportService::class)->export($admin, 'prospects', ['project_id' => self::$ids['projectA']], $out);
        $this->assertSame(2, $rows);
        rewind($out);
        $csv = stream_get_contents($out);
        $this->assertContains("'=cmd|calc!A1", $csv);
        $this->assertContains("'@SUM(1)", $csv);
        $this->assertNotContains(',=cmd', $csv);
        $this->assertSame(1, (int)TestEnv::db()->fetchColumn("SELECT COUNT(*) FROM export_jobs WHERE type = 'prospects'"));
    }

    public function testBackupAndRestoreRoundTrip(): void
    {
        $svc = TestEnv::app()->make(BackupService::class);
        TestEnv::db()->update('clients', ['notes' => "Multi-line;\nnote with 'quotes' and ; semicolons\\"], 'id = ?', [self::$ids['clientA']]);
        $before = TestEnv::db()->fetchAll('SELECT id, name, notes FROM clients ORDER BY id');
        $countBefore = (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM prospect_pages');
        $chainBefore = TestEnv::app()->make(AuditLogger::class)->verifyChain();
        $auditCount = (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM audit_logs');
        $file = $svc->backup();
        $this->assertTrue(is_file($file) && filesize($file) > 100);
        TestEnv::db()->run('SET FOREIGN_KEY_CHECKS = 0');
        TestEnv::db()->run('DELETE FROM prospect_pages');
        TestEnv::db()->run("UPDATE clients SET name = CONCAT(name, ' (changed)')");
        TestEnv::db()->run('SET FOREIGN_KEY_CHECKS = 1');
        $svc->restore($file);
        $this->assertSame($before, TestEnv::db()->fetchAll('SELECT id, name, notes FROM clients ORDER BY id'));
        $this->assertSame($countBefore, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM prospect_pages'));
        $this->assertSame($chainBefore, TestEnv::app()->make(AuditLogger::class)->verifyChain(), 'Audit chain restored exactly as it was');
        $this->assertSame($auditCount, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM audit_logs'));
    }

    public function testInstallerCreatesAdminAndLocks(): void
    {
        $db = TestEnv::dbConfig('bseo_install_test');
        $pdo = new \PDO(sprintf('mysql:host=%s;port=%d', $db['host'], $db['port']), $db['user'], $db['pass']);
        $pdo->exec('DROP DATABASE IF EXISTS bseo_install_test');
        $pdo->exec('CREATE DATABASE bseo_install_test CHARACTER SET utf8mb4');
        $storage = TestEnv::$storage . '/install-storage';
        @mkdir($storage, 0777, true);
        $configPath = TestEnv::$storage . '/install-config/config.php';
        $prevEnv = getenv('BHARATSEO_CONFIG');
        putenv('BHARATSEO_CONFIG=' . $configPath);
        try {
            $in = ['db_host' => $db['host'], 'db_port' => $db['port'], 'db_name' => 'bseo_install_test', 'db_user' => $db['user'], 'db_pass' => $db['pass'], 'app_url' => 'https://seo.example.in', 'timezone' => 'Asia/Kolkata', 'admin_name' => 'First Admin', 'admin_email' => 'first@agency.test', 'admin_password' => 'weak'];
            $this->assertThrows(\InvalidArgumentException::class, fn() => Installer::install($in, $configPath, $storage), 'Weak admin password rejected');
            $in['admin_password'] = 'Very-Strong-Pass1';
            $r = Installer::install($in, $configPath, $storage);
            $this->assertSame($configPath, $r['config_path']);
            $cfg = require $configPath;
            $this->assertSame('production', $cfg['app']['env']);
            $this->assertFalse($cfg['app']['debug']);
            $this->assertTrue(str_starts_with($cfg['app']['key'], 'base64:'));
            $this->assertSame(64, strlen($cfg['cron']['web_trigger_secret']));
            $this->assertTrue(is_file($storage . '/installed.lock'));
            $chk = new \PDO(sprintf('mysql:host=%s;port=%d;dbname=bseo_install_test', $db['host'], $db['port']), $db['user'], $db['pass']);
            $this->assertSame('super_admin', $chk->query("SELECT role FROM users WHERE email = 'first@agency.test'")->fetchColumn());
            $this->assertSame(1, (int)$chk->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'No default accounts are created');
            $this->assertThrows(\RuntimeException::class, fn() => Installer::install($in, $configPath, $storage), 'Installer is locked after success');
            @unlink($storage . '/installed.lock');
            $this->assertTrue(Installer::isLocked($storage), 'Existing config alone also locks the installer');
        } finally {
            putenv($prevEnv === false ? 'BHARATSEO_CONFIG' : 'BHARATSEO_CONFIG=' . $prevEnv);
            $pdo->exec('DROP DATABASE IF EXISTS bseo_install_test');
            App::boot(true, TestEnv::config());
        }
    }
}
