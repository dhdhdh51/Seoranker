<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Http;

use BharatSeo\Core\Config;
use BharatSeo\Crawler\HttpClient;
use BharatSeo\Services\ApprovalService;
use BharatSeo\Services\BacklinkMonitor;
use BharatSeo\Services\BacklinkService;
use BharatSeo\Services\EvaluationService;
use BharatSeo\Services\NotificationService;
use BharatSeo\Services\OutreachService;
use BharatSeo\Services\ProspectService;
use BharatSeo\Tests\SkipTest;
use BharatSeo\Tests\Support\FakeFetcher;
use BharatSeo\Tests\Support\TestEnv;
use BharatSeo\Tests\TestCase;

/**
 * End-to-end HTTP tests against PHP's built-in server: every page for every role,
 * CSRF, security headers, cross-client URL manipulation, exports, installer lock and cron trigger.
 * The server runs with debug=false so PHP warnings are logged; the log must stay clean.
 */
final class HttpSmokeTest extends TestCase
{
    private static $proc = null;
    private static int $port = 0;
    private static array $ids = [];
    private static array $jars = [];
    private static string $cronSecret;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open') || !function_exists('curl_init')) {
            throw new SkipTest('proc_open/curl unavailable');
        }
        $app = TestEnv::freshApp('bseo_http_test');
        $ids = TestEnv::seed();
        self::seedData($app, $ids);
        self::$ids = $ids;

        self::$port = 18000 + random_int(0, 999);
        self::$cronSecret = bin2hex(random_bytes(32));
        $cfg = TestEnv::config('bseo_http_test');
        $cfg['app']['debug'] = false;
        $cfg['app']['url'] = 'http://127.0.0.1:' . self::$port;
        $cfg['cron'] = ['web_trigger_enabled' => true, 'web_trigger_secret' => self::$cronSecret];
        $cfgFile = TestEnv::$storage . '/http-config.php';
        file_put_contents($cfgFile, Config::export($cfg));
        file_put_contents(TestEnv::$storage . '/installed.lock', 'test');
        foreach (glob(TestEnv::$storage . '/logs/*.log') ?: [] as $f) {
            unlink($f);
        }
        $env = array_merge(getenv(), ['BHARATSEO_CONFIG' => $cfgFile]);
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', APP_ROOT . '/public', APP_ROOT . '/tests/dev-router.php'];
        self::$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', TestEnv::$storage . '/server.out', 'a'], 2 => ['file', TestEnv::$storage . '/server.out', 'a']], $pipes, APP_ROOT, $env);
        for ($i = 0; $i < 50; $i++) {
            $s = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($s) {
                fclose($s);
                break;
            }
            usleep(100000);
        }
        // installed.lock lives in storage; the real install.php checks APP_ROOT/storage, so also verify via config lock.
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
        foreach (self::$jars as $j) {
            @unlink($j);
        }
    }

    private static function seedData(\BharatSeo\Core\App $app, array &$ids): void
    {
        $fake = new FakeFetcher();
        $app->bind(HttpClient::class, fn() => $fake);
        $app->forget(BacklinkMonitor::class);
        $html = (string)file_get_contents(APP_ROOT . '/tests/fixtures/referring_page.html');
        $pA = TestEnv::project($ids['projectA']);
        $pB = TestEnv::project($ids['projectB']);
        $ps = $app->make(ProspectService::class);
        $url = 'https://www.krishihub.example/resources/organic-farming/';
        $fake->on($url, FakeFetcher::html($url, $html));
        $ids['prospectA'] = (int)$ps->createFromUrl($pA, $url, ['category' => 'resource_page', 'contact_email' => 'editor@krishihub.example'], null, $ids['manager'])['id'];
        $app->make(EvaluationService::class)->evaluate($ids['prospectA']);
        $p = $ps->get($ids['prospectA']);
        if ($p['manual_hold']) {
            $ps->releaseHold(TestEnv::user($ids['manager']), $ids['prospectA'], 'Hidden link reviewed manually for test data');
        }
        if ($p['status'] !== 'needs_review') {
            $ps->transition($ids['prospectA'], 'needs_review', null);
        }
        $app->make(ApprovalService::class)->decideProspect(TestEnv::user($ids['manager']), $ids['prospectA'], true, 'ok');
        $ids['prospectB'] = (int)$ps->createFromUrl($pB, 'https://gadgets.example/reviews/', ['category' => 'resource_page'], null, null)['id'];
        $ids['campaignA'] = $app->make(OutreachService::class)->saveCampaign(TestEnv::user($ids['manager']), null, ['project_id' => $ids['projectA'], 'name' => 'Resources', 'type' => 'resource_page', 'status' => 'active', 'follow_up_days' => 7, 'max_follow_ups' => 1]);
        $ids['messageA'] = $app->make(OutreachService::class)->createDraft(TestEnv::user($ids['manager']), $ids['campaignA'], $ids['prospectA']);
        $app->make(OutreachService::class)->submitForApproval(TestEnv::user($ids['manager']), $ids['messageA']);
        $ids['backlinkA'] = (int)$app->make(BacklinkService::class)->createRecord($pA, $url, 'https://greenfarm.in/guides/composting/', null, 'manual', $ids['prospectA'], null)['id'];
        $app->make(BacklinkMonitor::class)->check($ids['backlinkA']);
        $ids['backlinkB'] = (int)$app->make(BacklinkService::class)->createRecord($pB, 'https://gadgets.example/reviews/', 'https://techshop.example/', null, 'manual', null, null)['id'];
        $app->make(NotificationService::class)->notify($ids['admin'], 'test', 'Welcome', 'Test notification', '/');
        $ids['campaignB'] = $app->make(OutreachService::class)->saveCampaign(TestEnv::user($ids['admin']), null, ['project_id' => $ids['projectB'], 'name' => 'B camp', 'type' => 'general', 'status' => 'active', 'follow_up_days' => 7, 'max_follow_ups' => 1]);
        $ids['unsub'] = (string)$app->db()->fetchColumn('SELECT unsubscribe_token FROM outreach_messages WHERE id = ?', [$ids['messageA']]);
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    private function http(string $method, string $path, ?string $role = null, array $data = [], array $headers = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        $respHeaders = [];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ];
        if ($role !== null) {
            $opts[CURLOPT_COOKIEJAR] = self::$jars[$role];
            $opts[CURLOPT_COOKIEFILE] = self::$jars[$role];
        }
        if ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
        }
        curl_setopt_array($ch, $opts);
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body];
    }

    private function csrf(string $role, string $path = '/profile'): string
    {
        $r = $this->http('GET', $path, $role);
        preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $r['body'], $m) || preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['body'], $m);
        return $m[1] ?? '';
    }

    private function login(string $role, string $email, string $password = 'Test#Passw0rd9'): void
    {
        self::$jars[$role] = tempnam(sys_get_temp_dir(), 'jar');
        $page = $this->http('GET', '/login', $role);
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $page['body'], $m);
        $r = $this->http('POST', '/login', $role, ['_csrf' => $m[1] ?? '', 'email' => $email, 'password' => $password]);
        $this->assertSame(303, $r['status']);
        $this->assertSame('/', parse_url($r['headers']['location'] ?? '', PHP_URL_PATH), "Login for $role should redirect to dashboard");
    }

    private function assertCleanPage(array $r, string $path): void
    {
        $this->assertSame(200, $r['status'], "GET $path → {$r['status']}: " . strip_tags(mb_substr($r['body'], 0, 400)));
        foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Stack trace', 'Exception', 'Uncaught'] as $bad) {
            $this->assertNotContains($bad, $r['body'], "$path contains '$bad'");
        }
        $this->assertContains('name="viewport"', $r['body'], 'Responsive viewport meta present');
        $html = preg_replace('#<(script|textarea|pre)\b.*?</\1>#s', '', $r['body']) ?? '';
        $this->assertSame(preg_match_all('#<div\b#', $html), preg_match_all('#</div>#', $html), "$path has unbalanced <div> tags");
    }

    public function test01SecurityHeadersAndGuestPages(): void
    {
        $r = $this->http('GET', '/login');
        $this->assertSame(200, $r['status']);
        $this->assertContains("default-src 'self'", $r['headers']['content-security-policy'] ?? '');
        $this->assertContains("frame-ancestors 'none'", $r['headers']['content-security-policy'] ?? '');
        $this->assertSame('DENY', $r['headers']['x-frame-options'] ?? '');
        $this->assertSame('nosniff', $r['headers']['x-content-type-options'] ?? '');
        $this->assertSame(false, isset($r['headers']['x-powered-by']));
        $this->assertSame(200, $this->http('GET', '/forgot-password')['status']);
        $this->assertSame(303, $this->http('GET', '/projects')['status'], 'Protected pages redirect guests to login');
        $this->assertSame(404, $this->http('GET', '/no-such-page')['status']);
        $page = $this->http('GET', '/login');
        $this->assertContains('HttpOnly', $page['headers']['set-cookie'] ?? '', 'Session cookie is HttpOnly');
        $this->assertContains('SameSite=Lax', $page['headers']['set-cookie'] ?? '');
    }

    public function test02LoginFailureAndCsrf(): void
    {
        self::$jars['anon'] = tempnam(sys_get_temp_dir(), 'jar');
        $page = $this->http('GET', '/login', 'anon');
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $page['body'], $m);
        $bad = $this->http('POST', '/login', 'anon', ['_csrf' => $m[1], 'email' => 'root@agency.test', 'password' => 'nope']);
        $this->assertSame('/login', parse_url($bad['headers']['location'] ?? '', PHP_URL_PATH));
        $follow = $this->http('GET', '/login', 'anon');
        $this->assertContains('Invalid email or password', $follow['body']);
        $noCsrf = $this->http('POST', '/login', 'anon', ['email' => 'root@agency.test', 'password' => 'Test#Passw0rd9']);
        $this->assertSame(303, $noCsrf['status'], 'Missing CSRF token is rejected (redirect back with a warning)');
        $after = $this->http('GET', '/', 'anon');
        $this->assertSame(303, $after['status'], 'Still not logged in');
    }

    public function test03AllPagesRenderForSuperAdmin(): void
    {
        $this->login('admin', 'root@agency.test');
        $i = self::$ids;
        $paths = ['/', '/?project_id=' . $i['projectA'] . '&from=2026-01-01&to=2026-12-31', '/clients', '/clients/new', '/clients/' . $i['clientA'], '/clients/' . $i['clientA'] . '/edit',
            '/projects', '/projects/new', '/projects/' . $i['projectA'], '/projects/' . $i['projectA'] . '/edit', '/discovery', '/discovery?project_id=' . $i['projectA'],
            '/prospects', '/prospects?status=approved&band=high&sort=newest', '/prospects/' . $i['prospectA'], '/prospects/' . $i['prospectA'] . '/assessment',
            '/outreach', '/outreach/campaigns/new', '/outreach/campaigns/' . $i['campaignA'], '/outreach/messages/' . $i['messageA'], '/outreach/suppressions',
            '/approvals', '/backlinks', '/backlinks?project_id=' . $i['projectA'], '/backlinks/' . $i['backlinkA'], '/backlinks/history', '/backlinks/history?changes=1',
            '/reports', '/reports/project/' . $i['projectA'], '/notifications', '/users', '/users/new', '/users/' . $i['manager'] . '/edit', '/settings',
            '/system/queue', '/system/queue?status=pending', '/system/audit', '/system/install', '/profile', '/audits', '/audits?project_id=' . $i['projectA']];
        foreach ($paths as $p) {
            $this->assertCleanPage($this->http('GET', $p, 'admin'), $p);
        }
        $dash = $this->http('GET', '/', 'admin')['body'];
        $this->assertContains('Cron is not configured', $dash, 'Dashboard warns honestly that automation is inactive');
        $this->assertContains('data-chart="bars"', $dash);
    }

    public function test04ManagerAndClientPermissions(): void
    {
        $this->login('manager', 'manager@agency.test');
        $this->login('clientA', 'owner@client-a.test');
        $i = self::$ids;
        foreach (['/', '/projects', '/projects/' . $i['projectA'], '/discovery', '/prospects', '/prospects/' . $i['prospectA'], '/outreach', '/outreach/messages/' . $i['messageA'], '/approvals', '/backlinks', '/reports/project/' . $i['projectA']] as $p) {
            $this->assertCleanPage($this->http('GET', $p, 'manager'), "manager $p");
        }
        foreach (['/', '/projects', '/projects/' . $i['projectA'], '/prospects', '/prospects/' . $i['prospectA'], '/approvals', '/backlinks', '/backlinks/' . $i['backlinkA'], '/reports', '/reports/project/' . $i['projectA'], '/outreach/messages/' . $i['messageA'], '/notifications'] as $p) {
            $this->assertCleanPage($this->http('GET', $p, 'clientA'), "client $p");
        }
        foreach (['/settings', '/users', '/system/queue', '/system/audit', '/clients', '/discovery', '/projects/new'] as $p) {
            $this->assertSame(403, $this->http('GET', $p, 'clientA')['status'], "client must get 403 on $p");
        }
        foreach (['/settings', '/users', '/system/queue'] as $p) {
            $this->assertSame(403, $this->http('GET', $p, 'manager')['status'], "manager must get 403 on $p");
        }
        $msg = $this->http('GET', '/outreach/messages/' . $i['messageA'], 'clientA')['body'];
        $this->assertNotContains('editor@krishihub.example', $msg, 'Client does not see prospect contact emails');
        $appr = $this->http('GET', '/approvals', 'clientA')['body'];
        $this->assertContains('Nothing awaiting your approval', $appr, 'Policy "manager" → client has nothing to approve');
    }

    public function test05CrossClientUrlManipulationIsBlocked(): void
    {
        $i = self::$ids;
        foreach (['/projects/' . $i['projectB'], '/prospects/' . $i['prospectB'], '/backlinks/' . $i['backlinkB'], '/reports/project/' . $i['projectB'], '/exports/prospects?project_id=' . $i['projectB'], '/outreach/campaigns/' . $i['campaignB'], '/prospects?project_id=' . $i['projectB'], '/clients/' . $i['clientB']] as $p) {
            $s = $this->http('GET', $p, 'clientA')['status'];
            $this->assertTrue(in_array($s, [403, 404], true), "client A must not reach $p (got $s)");
            $s2 = $this->http('GET', $p, 'manager')['status'];
            $this->assertTrue(in_array($s2, [403, 404], true), "manager of A must not reach $p (got $s2)");
        }
        $token = $this->csrf('manager');
        $r = $this->http('POST', '/prospects/' . $i['prospectB'] . '/decision', 'manager', ['_csrf' => $token, 'decision' => 'approve']);
        $this->assertSame(404, $r['status']);
        $this->assertSame('discovered', TestEnv::db()->fetchColumn('SELECT status FROM prospect_pages WHERE id = ?', [$i['prospectB']]));
        $r2 = $this->http('POST', '/backlinks/' . $i['backlinkB'] . '/delete', 'manager', ['_csrf' => $token]);
        $this->assertSame(404, $r2['status']);
        $this->assertTrue((bool)TestEnv::db()->fetchColumn('SELECT 1 FROM backlink_records WHERE id = ?', [$i['backlinkB']]));
    }

    public function test06FormsWorkEndToEndWithCsrf(): void
    {
        $token = $this->csrf('admin');
        // Without CSRF nothing changes.
        $before = (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM clients');
        $this->http('POST', '/clients', 'admin', ['name' => 'No Token Ltd']);
        $this->assertSame($before, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM clients'));
        // With CSRF the client is created.
        $r = $this->http('POST', '/clients', 'admin', ['_csrf' => $token, 'name' => 'Bharat Organics', 'contact_email' => 'ops@bharat-organics.test', 'status' => 'active']);
        $this->assertSame(303, $r['status']);
        $cid = (int)TestEnv::db()->fetchColumn("SELECT id FROM clients WHERE name = 'Bharat Organics'");
        $this->assertTrue($cid > 0);
        // Validation errors redirect back with a message, not a 500.
        $bad = $this->http('POST', '/projects', 'admin', ['_csrf' => $token, 'client_id' => $cid, 'name' => 'X', 'website_url' => 'not a url']);
        $this->assertSame(303, $bad['status']);
        $r2 = $this->http('POST', '/projects', 'admin', ['_csrf' => $token, 'client_id' => $cid, 'name' => 'Organics site', 'website_url' => 'https://bharat-organics.test/', 'keywords' => "millets\nghee", 'approval_policy' => 'manager']);
        $this->assertSame(303, $r2['status']);
        $pid = (int)TestEnv::db()->fetchColumn("SELECT id FROM projects WHERE domain = 'bharat-organics.test'");
        $this->assertTrue($pid > 0);
        $r3 = $this->http('POST', '/discovery/manual', 'admin', ['_csrf' => $token, 'project_id' => $pid, 'category' => 'resource_page', 'urls' => "https://millet-guide.example/links\nhttps://millet-guide.example/links/"]);
        $this->assertSame(303, $r3['status']);
        $this->assertSame(1, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM prospect_pages WHERE project_id = ?', [$pid]));
        $this->assertContains('1 new, 1 duplicate', $this->http('GET', '/discovery?project_id=' . $pid, 'admin')['body']);
        // Pause / resume queue.
        $this->http('POST', '/system/queue/pause', 'admin', ['_csrf' => $token]);
        $this->assertSame('1', TestEnv::db()->fetchColumn("SELECT value FROM application_settings WHERE setting_key = 'queue.paused'"));
        $this->http('POST', '/system/queue/resume', 'admin', ['_csrf' => $token]);
        $this->assertSame('0', TestEnv::db()->fetchColumn("SELECT value FROM application_settings WHERE setting_key = 'queue.paused'"));
        // Settings: secret stored encrypted and never echoed.
        $this->http('POST', '/settings', 'admin', ['_csrf' => $token, 'settings' => ['smtp.password' => 'Very$ecretSMTP', 'outreach.daily_limit' => '25']]);
        $page = $this->http('GET', '/settings', 'admin')['body'];
        $this->assertNotContains('Very$ecretSMTP', $page);
        $this->assertContains('stored encrypted', $page);
        $this->assertSame('25', TestEnv::db()->fetchColumn("SELECT value FROM application_settings WHERE setting_key = 'outreach.daily_limit'"));
    }

    public function test07ExportsAndPrintableReport(): void
    {
        $i = self::$ids;
        $r = $this->http('GET', '/exports/prospects?project_id=' . $i['projectA'], 'admin');
        $this->assertSame(200, $r['status']);
        $this->assertContains('text/csv', $r['headers']['content-type'] ?? '');
        $this->assertContains('attachment;', $r['headers']['content-disposition'] ?? '');
        $this->assertTrue(str_starts_with($r['body'], "\xEF\xBB\xBF"), 'UTF-8 BOM for spreadsheets');
        $this->assertContains('krishihub.example', $r['body']);
        $this->assertNotContains('gadgets.example', $r['body'], 'Export scoped to the selected project');
        $c = $this->http('GET', '/exports/prospects', 'clientA');
        $this->assertSame(200, $c['status']);
        $this->assertNotContains('gadgets.example', $c['body'], 'Client export never includes other clients');
        $rep = $this->http('GET', '/reports/project/' . $i['projectA'], 'clientA')['body'];
        $this->assertContains('Generated', $rep);
        $this->assertContains('not search-engine index data', $rep);
        $this->assertSame(303, $this->http('GET', '/reports?project=' . $i['projectA'], 'admin')['status']);
        foreach (['backlinks', 'history', 'drafts', 'report'] as $t) {
            $this->assertSame(200, $this->http('GET', '/exports/' . $t . '?project_id=' . $i['projectA'], 'admin')['status'], "export $t");
        }
        $this->login('clientB', 'owner@client-b.test');
        $this->assertSame(404, $this->http('GET', '/exports/prospects?project_id=' . $i['projectA'], 'clientB')['status']);
        $this->assertSame(403, $this->http('GET', '/exports/prospects?project_id=' . $i['projectB'], 'clientB')['status'], 'Client without export permission');
    }

    public function test08ApprovalThroughUi(): void
    {
        $i = self::$ids;
        $token = $this->csrf('manager');
        $req = (int)TestEnv::db()->fetchColumn("SELECT id FROM approval_requests WHERE entity_type = 'outreach_message' AND entity_id = ? AND status = 'pending'", [$i['messageA']]);
        $deny = $this->http('POST', '/approvals/' . $req . '/decide', 'clientA', ['_csrf' => $this->csrf('clientA'), 'decision' => 'approve']);
        $this->assertSame(403, $deny['status'], 'Client cannot approve a manager-party request');
        $ok = $this->http('POST', '/approvals/' . $req . '/decide', 'manager', ['_csrf' => $token, 'decision' => 'approve']);
        $this->assertSame(303, $ok['status']);
        $this->assertSame('approved', TestEnv::db()->fetchColumn('SELECT status FROM outreach_messages WHERE id = ?', [$i['messageA']]));
    }

    public function test09UnsubscribeInstallerLockAndCronTrigger(): void
    {
        $tok = self::$ids['unsub'];
        $this->assertCleanPageNoViewportCheck($this->http('GET', '/unsubscribe/' . $tok));
        $r = $this->http('POST', '/unsubscribe/' . $tok, null, ['List-Unsubscribe' => 'One-Click']);
        $this->assertSame(200, $r['status'], 'RFC 8058 one-click POST works without session/CSRF');
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM email_suppressions WHERE email = 'editor@krishihub.example'"));
        $this->assertContains('Link not recognised', $this->http('GET', '/unsubscribe/' . str_repeat('0', 64))['body']);

        $inst = $this->http('GET', '/install.php');
        $this->assertSame(403, $inst['status']);
        $this->assertContains('Installer locked', $inst['body']);

        $this->assertSame(403, $this->http('GET', '/cron.php?task=process_queue&key=wrong')['status']);
        $ok = $this->http('GET', '/cron.php?task=process_queue', null, [], ['X-Bharat-Cron-Key: ' . self::$cronSecret]);
        $this->assertSame(200, $ok['status'], $ok['body']);
        $this->assertContains('"status":"completed"', $ok['body']);
        $this->assertSame(429, $this->http('GET', '/cron.php?task=process_queue', null, [], ['X-Bharat-Cron-Key: ' . self::$cronSecret])['status'], 'Per-task rate limit');
        $this->assertSame('web', TestEnv::db()->fetchColumn("SELECT trigger_type FROM cron_runs ORDER BY id DESC LIMIT 1"));
        foreach (['/../app/bootstrap.php', '/../storage/installed.lock', '/.htaccess'] as $p) {
            $s = $this->http('GET', $p)['status'];
            $this->assertTrue($s === 404 || $s === 403 || $s === 400, "Private path $p must not be served (got $s)");
        }
    }

    private function assertCleanPageNoViewportCheck(array $r): void
    {
        $this->assertSame(200, $r['status']);
        $this->assertNotContains('Exception', $r['body']);
    }

    public function test10LogoutAndNoPhpWarningsLogged(): void
    {
        $token = $this->csrf('admin');
        $this->assertSame(303, $this->http('POST', '/logout', 'admin', ['_csrf' => $token])['status']);
        $this->assertSame(303, $this->http('GET', '/', 'admin')['status'], 'Session destroyed on logout');
        $logs = '';
        foreach (glob(TestEnv::$storage . '/logs/*.log') ?: [] as $f) {
            $logs .= (string)file_get_contents($f);
        }
        $this->assertNotContains('PHP:', $logs, 'No PHP warnings/notices during the whole HTTP run: ' . mb_substr($logs, 0, 600));
        $this->assertNotContains('ERROR', $logs, 'No unhandled errors: ' . mb_substr($logs, 0, 600));
    }
}
