<?php
declare(strict_types=1);

namespace Flatmates\Tests\Http;

use Flatmates\Core\App;
use Flatmates\Core\Config;
use Flatmates\Services\ListingService;
use Flatmates\Tests\SkipTest;
use Flatmates\Tests\Support\TestEnv;
use Flatmates\Tests\TestCase;

/**
 * End-to-end tests through PHP's built-in web server (debug off): full student journey, every
 * page per role, CSRF, security headers, IDOR / access control, media privacy, SEO endpoints,
 * installer lock and the web cron trigger. The PHP error log must stay clean.
 */
final class HttpSmokeTest extends TestCase
{
    private static $proc = null;
    private static int $port = 0;
    private static array $jars = [];
    private static array $ids = [];
    private static string $cronSecret = '';

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open') || !function_exists('curl_init')) {
            throw new SkipTest('proc_open/curl unavailable');
        }
        $app = TestEnv::freshApp('fm_http_test');
        $app->settings()->set('smtp.driver', 'log');
        $ids = [];
        $ids['admin'] = TestEnv::makeUser('admin@example.com', 'super_admin');
        $ids['mod'] = TestEnv::makeUser('mod@example.com', 'moderator', ['permissions' => 'reports,listings']);
        $ids['bob'] = TestEnv::student('bob@example.com');
        $ids['carol'] = TestEnv::student('carol@example.com');
        $ids['owner'] = TestEnv::student('owner@example.com');
        $app->settings()->set('rules.listing_review', false);
        $owner = TestEnv::user($ids['owner']);
        $ls = $app->make(ListingService::class);
        $ids['listing'] = $ls->create($owner, ['type' => 'shared_room', 'title' => 'Shared room near Amity campus', 'city_id' => '1', 'locality' => 'Sector 125', 'address_private' => 'H-12 Hidden Street', 'rent' => '7000', 'description' => 'Clean shared room with a balcony, ten minutes from campus. Wifi and housekeeping included.', 'amenities' => ['wifi']], true);
        $ls->addPhotos($owner, $ids['listing'], [TestEnv::upload(TestEnv::jpeg())]);
        $ids['members_listing'] = $ls->create($owner, ['type' => 'flat', 'title' => 'Members only 2BHK flat', 'city_id' => '1', 'locality' => 'Sector 50', 'rent' => '20000', 'description' => 'A whole 2BHK flat for two students, shared with signed-in members only.', 'visibility' => 'members'], true);
        $ids['draft'] = $ls->create($owner, ['type' => 'flat', 'title' => 'Draft listing not public', 'city_id' => '1', 'locality' => 'Sector 18', 'rent' => '15000', 'description' => 'This is a draft listing and must never be visible to anyone else.'], false);
        $app->settings()->set('rules.listing_review', true);
        $ids['listing_slug'] = (string)$app->db()->fetchColumn('SELECT slug FROM listings WHERE id = ?', [$ids['listing']]);
        $ids['members_slug'] = (string)$app->db()->fetchColumn('SELECT slug FROM listings WHERE id = ?', [$ids['members_listing']]);
        $ids['draft_slug'] = (string)$app->db()->fetchColumn('SELECT slug FROM listings WHERE id = ?', [$ids['draft']]);
        $ids['photo'] = (string)$app->db()->fetchColumn('SELECT path FROM listing_photos WHERE listing_id = ?', [$ids['listing']]);
        // bob <-> carol matched with a conversation
        $ms = $app->make(\Flatmates\Services\MatchService::class);
        $ms->swipe(TestEnv::user($ids['bob']), $ids['carol'], 'like');
        $r = $ms->swipe(TestEnv::user($ids['carol']), $ids['bob'], 'like');
        $ids['conv'] = (int)$r['conversation_id'];
        $app->make(\Flatmates\Services\ChatService::class)->send(TestEnv::user($ids['carol']), $ids['conv'], 'Hello Bob <script>alert(1)</script>');
        // carol has a private profile photo
        $app->make(\Flatmates\Services\ProfileService::class)->saveStep(TestEnv::user($ids['carol']), 4, ['discoverable' => '1', 'visibility' => 'members'], TestEnv::upload(TestEnv::jpeg()));
        $ids['carol_photo'] = (string)$app->db()->fetchColumn('SELECT photo_path FROM profiles WHERE user_id = ?', [$ids['carol']]);
        self::$ids = $ids;

        self::$port = 18100 + random_int(0, 800);
        self::$cronSecret = bin2hex(random_bytes(32));
        $cfg = TestEnv::config('fm_http_test');
        $cfg['app']['debug'] = false;
        $cfg['app']['url'] = 'http://127.0.0.1:' . self::$port;
        $cfg['cron'] = ['web_trigger_enabled' => true, 'web_trigger_secret' => self::$cronSecret];
        $file = TestEnv::$storage . '/http-config.php';
        file_put_contents($file, Config::export($cfg));
        file_put_contents(TestEnv::$storage . '/installed.lock', 'test');
        foreach (glob(TestEnv::$storage . '/logs/*.log') ?: [] as $f) {
            unlink($f);
        }
        $env = array_merge(getenv(), ['FLATMATES_CONFIG' => $file]);
        self::$proc = proc_open([PHP_BINARY, '-d', 'error_log=' . TestEnv::$storage . '/logs/php-server.log', '-d', 'log_errors=1', '-S', '127.0.0.1:' . self::$port, '-t', APP_ROOT . '/public', APP_ROOT . '/tests/dev-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', TestEnv::$storage . '/server.out', 'a'], 2 => ['file', TestEnv::$storage . '/server.out', 'a']], $pipes, APP_ROOT, $env);
        for ($i = 0; $i < 50; $i++) {
            if ($s = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2)) {
                fclose($s);
                return;
            }
            usleep(100000);
        }
        throw new SkipTest('Built-in server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proc) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
        TestEnv::cleanupStorage();
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    private function req(string $method, string $path, ?string $as = null, array|string|null $data = null, array $headers = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        $jar = $as ? (self::$jars[$as] ??= tempnam(sys_get_temp_dir(), 'jar')) : null;
        $respHeaders = [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers + [], CURLOPT_HEADERFUNCTION => static function ($c, $h) use (&$respHeaders) {
                $p = explode(':', $h, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($h);
            }]);
        if ($jar) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) && !array_filter($data, static fn($v) => $v instanceof \CURLFile) ? http_build_query($data) : $data);
        }
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body, 'headers' => $respHeaders];
    }

    private function csrf(string $as, string $page = '/login'): string
    {
        $r = $this->req('GET', $page, $as);
        preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $r['body'], $m);
        return $m[1] ?? '';
    }

    private function login(string $as, string $email, string $password = 'Test#Passw0rd9'): void
    {
        $r = $this->req('POST', '/login', $as, ['_csrf' => $this->csrf($as), 'email' => $email, 'password' => $password]);
        $this->assertSame(303, $r['status'], 'login redirect');
        $this->assertNotContains('/login', $r['headers']['location'] ?? '', "login as $email should succeed");
    }

    private function post(string $as, string $path, array $data = [], array $headers = []): array
    {
        return $this->req('POST', $path, $as, $data + ['_csrf' => $this->csrf($as, '/account')], $headers);
    }

    private function mailCode(): string
    {
        $files = glob(TestEnv::$storage . '/mail/*.eml') ?: [];
        usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
        $raw = quoted_printable_decode((string)file_get_contents($files[0] ?? ''));
        preg_match('/Your code is: (\d{6})/', $raw, $m);
        return $m[1] ?? '';
    }

    public function testPublicPagesSeoAndHeaders(): void
    {
        $home = $this->req('GET', '/');
        $this->assertSame(200, $home['status']);
        $this->assertContains("default-src 'self'", $home['headers']['content-security-policy'] ?? '');
        $this->assertSame('DENY', $home['headers']['x-frame-options'] ?? '');
        $this->assertSame('nosniff', $home['headers']['x-content-type-options'] ?? '');
        $this->assertContains('HttpOnly', $home['headers']['set-cookie'] ?? '');
        $this->assertContains('SameSite=Lax', $home['headers']['set-cookie'] ?? '');
        $this->assertContains('application/ld+json', $home['body']);
        $this->assertContains('rel="canonical"', $home['body']);
        $this->assertNotContains('<script>', preg_replace('#<script (src|type)=[^>]+>#', '', $home['body']), 'no inline scripts');
        foreach (['/rooms', '/cities', '/flatmates/noida', '/flatmates/noida/amity-university-noida', '/about', '/safety', '/terms', '/privacy', '/login', '/register', '/forgot-password', '/rooms/' . self::$ids['listing_slug'], '/rooms?city_id=1&type=shared_room&amenities[]=wifi'] as $p) {
            $r = $this->req('GET', $p);
            $this->assertSame(200, $r['status'], "GET $p");
        }
        $listing = $this->req('GET', '/rooms/' . self::$ids['listing_slug']);
        $this->assertNotContains('H-12 Hidden Street', $listing['body'], 'private address never public');
        $this->assertNotContains('owner@example.com', $listing['body']);
        $this->assertContains('"@type":"Accommodation"', $listing['body']);
        $this->assertSame(404, $this->req('GET', '/rooms/' . self::$ids['draft_slug'])['status'], 'draft hidden');
        $this->assertSame(403, $this->req('GET', '/rooms/' . self::$ids['members_slug'])['status'], 'members-only listing');
        $this->assertSame(404, $this->req('GET', '/flatmates/atlantis')['status']);
        $this->assertSame(404, $this->req('GET', '/no-such-page')['status']);
        $sm = $this->req('GET', '/sitemap.xml');
        $this->assertContains('/rooms/' . self::$ids['listing_slug'], $sm['body']);
        $this->assertNotContains(self::$ids['draft_slug'], $sm['body']);
        $this->assertNotContains(self::$ids['members_slug'], $sm['body']);
        $this->assertContains('/flatmates/noida/amity-university-noida', $sm['body']);
        $robots = $this->req('GET', '/robots.txt');
        $this->assertContains('Disallow: /admin', $robots['body']);
        $this->assertContains('Sitemap: http://127.0.0.1:' . self::$port . '/sitemap.xml', $robots['body']);
        $this->assertSame(403, $this->req('GET', '/install.php')['status'], 'installer locked');
        $this->assertSame(403, $this->req('GET', '/.htaccess')['status']);
        $this->assertSame(301, $this->req('GET', '/rooms/')['status']);
    }

    public function testMediaAccessControl(): void
    {
        $this->assertSame(200, $this->req('GET', '/media/' . self::$ids['photo'])['status'], 'public listing photo');
        $this->assertSame(404, $this->req('GET', '/media/' . self::$ids['carol_photo'])['status'], 'profile photo needs sign-in');
        $this->assertSame(404, $this->req('GET', '/media/photos/' . str_repeat('a', 32) . '.jpg', 'bob')['status']);
        $this->assertSame(404, $this->req('GET', '/media/../config/config.php')['status']);
        $this->login('bob', 'bob@example.com');
        $r = $this->req('GET', '/media/' . self::$ids['carol_photo'], 'bob');
        $this->assertSame(200, $r['status']);
        $this->assertSame('image/jpeg', $r['headers']['content-type'] ?? '');
    }

    public function testFullStudentJourney(): void
    {
        $as = 'new';
        // Register
        $r = $this->req('POST', '/register', $as, ['_csrf' => $this->csrf($as, '/register'), 'name' => 'Neha Verma', 'email' => 'neha@example.com', 'password' => 'Blue#Kettle42', 'password_confirmation' => 'Blue#Kettle42', 'date_of_birth' => '2004-08-09', 'age_confirmed' => '1', 'terms' => '1', 'website' => '']);
        $this->assertSame('/verify-email', $r['headers']['location'] ?? '');
        $this->assertContains('Check your email', $this->req('GET', '/verify-email', $as)['body']);
        $this->assertSame('/login', $this->req('GET', '/discover', $as)['headers']['location'] ?? '', 'not signed in until verified');
        $r = $this->req('POST', '/verify-email', $as, ['_csrf' => $this->csrf($as, '/verify-email'), 'code' => $this->mailCode()]);
        $this->assertSame('/onboarding/1', $r['headers']['location'] ?? '');
        $this->assertSame('/onboarding/1', $this->req('GET', '/discover', $as)['headers']['location'] ?? '', 'onboarding gate');
        $this->assertSame('/onboarding/1', $this->req('GET', '/onboarding/3', $as)['headers']['location'] ?? '', 'cannot skip steps');
        // Onboarding
        $csrf = $this->csrf($as, '/onboarding/1');
        $this->assertSame('/onboarding/2', $this->req('POST', '/onboarding/1', $as, ['_csrf' => $csrf, 'display_name' => 'Neha', 'accommodation_status' => 'looking_room', 'flatmate_gender_pref' => 'any', 'gender' => 'female', 'bio' => 'Design student, early riser.', 'languages' => ['Hindi', 'English']])['headers']['location'] ?? '');
        $bad = $this->req('POST', '/onboarding/2', $as, ['_csrf' => $csrf, 'city_id' => '1', 'budget_max' => '50']);
        $this->assertSame(303, $bad['status']);
        $this->assertContains('budget', strtolower($this->req('GET', '/onboarding/2', $as)['body']));
        $this->assertSame('/onboarding/3', $this->req('POST', '/onboarding/2', $as, ['_csrf' => $csrf, 'city_id' => '1', 'college_id' => '1', 'course' => 'B.Des', 'academic_year' => '2', 'locality' => 'Sector 62', 'budget_min' => '6000', 'budget_max' => '9000', 'move_in_date' => gmdate('Y-m-d', time() + 86400 * 25), 'room_type' => 'any'])['headers']['location'] ?? '');
        $this->assertSame('/onboarding/4', $this->req('POST', '/onboarding/3', $as, ['_csrf' => $csrf, 'cleanliness' => '4', 'sleep_schedule' => 'early', 'smoking' => 'never', 'smoking_ok' => '0', 'alcohol' => 'never', 'alcohol_ok' => '1', 'diet' => 'veg', 'study_habits' => 'quiet', 'social_level' => 'balanced', 'guests' => 'sometimes'])['headers']['location'] ?? '');
        $photo = new \CURLFile(TestEnv::jpeg(), 'image/jpeg', 'me.jpg');
        $this->assertSame('/discover', $this->req('POST', '/onboarding/4', $as, ['_csrf' => $csrf, 'discoverable' => '1', 'visibility' => 'members', 'show_course' => '1', 'photo' => $photo])['headers']['location'] ?? '');
        // Discover
        $page = $this->req('GET', '/discover', $as);
        $this->assertSame(200, $page['status']);
        $this->assertContains('swipe.js', $page['body']);
        $deck = json_decode($this->req('GET', '/api/deck', $as)['body'], true);
        $ids = array_column($deck['cards'], 'id');
        $this->assertTrue(in_array(self::$ids['bob'], $ids, true));
        $card = $deck['cards'][array_search(self::$ids['bob'], $ids, true)];
        $this->assertTrue(is_int($card['score']));
        $this->assertFalse(isset($card['email']) || isset($card['gender']));
        // Swipe needs CSRF
        $this->assertSame(419, $this->req('POST', '/api/swipe', $as, ['target_id' => self::$ids['bob'], 'action' => 'like'], ['Accept: application/json'])['status']);
        $tok = $this->csrf($as, '/discover');
        $sw = json_decode($this->req('POST', '/api/swipe', $as, ['target_id' => (string)self::$ids['bob'], 'action' => 'like'], ['X-CSRF-Token: ' . $tok, 'Accept: application/json'])['body'], true);
        $this->assertFalse($sw['matched']);
        // bob likes back → match
        $btok = $this->csrf('bob2', '/login');
        $this->login('bob2', 'bob@example.com');
        $btok = $this->csrf('bob2', '/discover');
        $nehaId = (int)App::get()->db()->fetchColumn("SELECT id FROM users WHERE email = 'neha@example.com'");
        $sw = json_decode($this->req('POST', '/api/swipe', 'bob2', ['target_id' => (string)$nehaId, 'action' => 'like'], ['X-CSRF-Token: ' . $btok])['body'], true);
        $this->assertTrue($sw['matched']);
        $conv = (int)$sw['conversation_id'];
        $this->assertContains('Neha', $this->req('GET', '/matches', 'bob2')['body']);
        // Chat via AJAX
        $sent = json_decode($this->req('POST', '/api/chat/' . $conv, $as, ['body' => 'Hi Bob! <img src=x onerror=alert(1)>'], ['X-CSRF-Token: ' . $tok])['body'], true);
        $this->assertSame(true, $sent['message']['mine']);
        $poll = json_decode($this->req('GET', '/api/chat/' . $conv . '?since=0', 'bob2')['body'], true);
        $this->assertSame('Hi Bob! <img src=x onerror=alert(1)>', end($poll['messages'])['body']);
        $html = $this->req('GET', '/chat/' . $conv, 'bob2')['body'];
        $this->assertContains('&lt;img src=x onerror=alert(1)&gt;', $html, 'escaped in HTML');
        $this->assertNotContains('<img src=x', $html);
        $badges = json_decode($this->req('GET', '/api/poll', $as)['body'], true);
        $this->assertTrue(isset($badges['messages'], $badges['notifications']));
        // Profile view, report and block
        $this->assertSame(200, $this->req('GET', '/profile/' . self::$ids['bob'], $as)['status']);
        $this->assertSame(303, $this->post($as, '/report', ['target_type' => 'user', 'target_id' => (string)self::$ids['bob'], 'reason' => 'spam', 'details' => 'test'])['status']);
        $this->assertSame(303, $this->post($as, '/block/' . self::$ids['bob'])['status']);
        $this->assertContains('You can no longer message this person', $this->req('GET', '/chat/' . $conv, $as)['body']);
        $send = $this->req('POST', '/api/chat/' . $conv, $as, ['body' => 'x'], ['X-CSRF-Token: ' . $tok]);
        $this->assertSame(403, $send['status']);
        $this->assertSame(404, $this->req('GET', '/profile/' . self::$ids['bob'], $as)['status']);
        // Listing journey: enquiry
        $this->assertSame(303, $this->post($as, '/rooms/' . self::$ids['listing'] . '/enquire', ['kind' => 'enquiry', 'message' => 'Hi, is the shared room still available from next month?'])['status']);
        $this->login('owner', 'owner@example.com');
        $this->assertContains('Neha', $this->req('GET', '/enquiries', 'owner')['body']);
        $eid = (int)App::get()->db()->fetchColumn('SELECT id FROM enquiries WHERE student_id = ?', [$nehaId]);
        $this->assertSame(404, $this->post($as, '/enquiries/' . $eid . '/respond', ['decision' => 'accept'])['status'], 'student cannot accept own enquiry');
        $acc = $this->post('owner', '/enquiries/' . $eid . '/respond', ['decision' => 'accept']);
        $this->assertContains('/chat/', $acc['headers']['location'] ?? '');
        $this->assertContains('H-12 Hidden Street', $this->req('GET', '/rooms/' . self::$ids['listing_slug'], $as)['body'], 'address shared after acceptance');
        // Post own listing with photos
        $ltok = $this->csrf($as, '/my/listings/new');
        $r = $this->req('POST', '/my/listings', $as, ['_csrf' => $ltok, 'type' => 'vacant_bed', 'title' => 'Vacant bed in girls 3BHK', 'city_id' => '1', 'locality' => 'Sector 62', 'rent' => '6500', 'gender_pref' => 'female', 'description' => 'One bed free in a quiet, clean 3BHK shared by design students. Near metro.', 'submit_review' => '1', 'photos[0]' => new \CURLFile(TestEnv::jpeg(), 'image/jpeg', 'a.jpg')]);
        $this->assertSame(303, $r['status']);
        $lid = (int)App::get()->db()->fetchColumn('SELECT id FROM listings WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$nehaId]);
        $this->assertSame('pending', App::get()->db()->fetchColumn('SELECT status FROM listings WHERE id = ?', [$lid]));
        $this->assertSame(1, (int)App::get()->db()->fetchColumn('SELECT COUNT(*) FROM listing_photos WHERE listing_id = ?', [$lid]));
        $this->assertSame(200, $this->req('GET', '/my/listings/' . $lid . '/edit', $as)['status']);
        $this->assertSame(404, $this->req('GET', '/my/listings/' . $lid . '/edit', 'owner')['status'], 'IDOR: other student cannot edit');
        $this->assertSame(404, $this->post('owner', '/my/listings/' . $lid . '/delete')['status']);
        // Saved + account pages + export
        $sv = json_decode($this->req('POST', '/api/save', $as, ['type' => 'listing', 'id' => (string)self::$ids['listing']], ['X-CSRF-Token: ' . $tok])['body'], true);
        $this->assertSame(true, $sv['saved']);
        foreach (['/saved', '/account', '/notifications', '/verify', '/profile', '/my/listings', '/enquiries?tab=sent', '/chat', '/matches', '/onboarding/2'] as $p) {
            $this->assertSame(200, $this->req('GET', $p, $as)['status'], "GET $p as student");
        }
        $ex = $this->req('GET', '/account/export', $as);
        $this->assertContains('attachment', $ex['headers']['content-disposition'] ?? '');
        $data = json_decode($ex['body'], true);
        $this->assertSame('neha@example.com', $data['account']['email']);
        $this->assertNotContains('password_hash', $ex['body']);
        $this->assertNotContains('$argon2', $ex['body']);
        // Students cannot reach admin
        foreach (['/admin', '/admin/users', '/admin/settings', '/admin/reports', '/admin/verifications'] as $p) {
            $this->assertSame(403, $this->req('GET', $p, $as)['status'], "student blocked from $p");
        }
        // Logout invalidates
        $this->assertSame(303, $this->post($as, '/logout')['status']);
        $this->assertSame('/login', $this->req('GET', '/discover', $as)['headers']['location'] ?? '');
        $this->assertSame(401, $this->req('GET', '/api/deck', $as)['status']);
    }

    public function testAdminAndModeratorPages(): void
    {
        $this->login('admin', 'admin@example.com');
        foreach (['/admin', '/admin/reports', '/admin/reports?status=all', '/admin/verifications', '/admin/listings', '/admin/listings?status=all', '/admin/users', '/admin/users?q=bob', '/admin/users/' . self::$ids['bob'], '/admin/locations', '/admin/settings', '/admin/settings?g=Email+%28SMTP%29', '/admin/settings?g=NoidaRankers+connection', '/admin/analytics', '/admin/audit', '/admin/health', '/rooms/' . self::$ids['draft_slug']] as $p) {
            $this->assertSame(200, $this->req('GET', $p, 'admin')['status'], "admin GET $p");
        }
        // Settings save: secret stored encrypted, never echoed back
        $r = $this->post('admin', '/admin/settings', ['group' => 'Email (SMTP)', 'smtp__driver' => 'log', 'smtp__host' => 'mail.example.com', 'smtp__port' => '587', 'smtp__encryption' => 'tls', 'smtp__username' => 'u', 'smtp__password' => 'S3cretPass!', 'smtp__from_email' => 'no-reply@example.com', 'smtp__from_name' => 'FM', 'mail__footer' => 'x']);
        $this->assertSame(303, $r['status']);
        $raw = (string)App::get()->db()->fetchColumn("SELECT value FROM application_settings WHERE setting_key = 'smtp.password'");
        $this->assertTrue(str_starts_with($raw, 'v1:'));
        $this->assertNotContains('S3cretPass!', $this->req('GET', '/admin/settings?g=Email+%28SMTP%29', 'admin')['body']);
        $this->assertSame(303, $this->post('admin', '/admin/settings', ['group' => 'Branding', 'brand__primary_color' => 'javascript:alert(1)'])['status']);
        $this->assertSame('#2f6fed', App::get()->db()->fetchColumn("SELECT value FROM application_settings WHERE setting_key = 'brand.primary_color'") ?: '#2f6fed', 'invalid colour rejected');
        // Location management
        $this->assertSame(303, $this->post('admin', '/admin/locations', ['kind' => 'college', 'name' => 'Test Institute', 'city_id' => '1', 'email_domain' => 'gmail.com', 'active' => '1'])['status']);
        $this->assertSame(0, (int)App::get()->db()->fetchColumn("SELECT COUNT(*) FROM colleges WHERE name = 'Test Institute'"));
        // Moderator with reports+listings only
        $this->login('mod', 'mod@example.com');
        foreach (['/admin' => 200, '/admin/reports' => 200, '/admin/listings' => 200, '/admin/verifications' => 403, '/admin/users' => 403, '/admin/settings' => 403, '/admin/audit' => 403] as $p => $code) {
            $this->assertSame($code, $this->req('GET', $p, 'mod')['status'], "moderator GET $p");
        }
        $this->assertSame(303, $this->post('mod', '/admin/listings/' . self::$ids['draft'], ['action' => 'approve'])['status']);
        $this->assertSame('published', App::get()->db()->fetchColumn('SELECT status FROM listings WHERE id = ?', [self::$ids['draft']]));
        $this->assertSame(403, $this->post('mod', '/admin/users/' . self::$ids['bob'] . '/status', ['status' => 'suspended', 'reason' => 'x'])['status']);
    }

    public function testCsrfAndMethodChecks(): void
    {
        $this->login('carol', 'carol@example.com');
        $r = $this->req('POST', '/account/delete', 'carol', ['password' => 'Test#Passw0rd9'], ['Referer: http://127.0.0.1:' . self::$port . '/account']);
        $this->assertSame(303, $r['status']);
        $this->assertSame(null, App::get()->db()->fetchColumn('SELECT deletion_requested_at FROM users WHERE id = ?', [self::$ids['carol']]), 'no CSRF token → nothing happens');
        $this->assertSame(405, $this->req('GET', '/logout', 'carol')['status']);
        $this->assertSame(404, $this->req('GET', '/chat/999999', 'carol')['status']);
    }

    public function testWebCronTriggerAndLogStaysClean(): void
    {
        $this->assertSame(403, $this->req('GET', '/cron.php?task=mail', null, null, ['X-Cron-Key: wrong'])['status']);
        $ok = $this->req('GET', '/cron.php?task=mail', null, null, ['X-Cron-Key: ' . self::$cronSecret]);
        $this->assertSame(200, $ok['status']);
        $this->assertContains('"status":"ok"', $ok['body']);
        $this->assertSame(429, $this->req('GET', '/cron.php?task=mail', null, null, ['X-Cron-Key: ' . self::$cronSecret])['status']);
        $logs = '';
        foreach (glob(TestEnv::$storage . '/logs/*.log') ?: [] as $f) {
            $logs .= file_get_contents($f);
        }
        $this->assertSame('', trim($logs), 'error log must be empty: ' . mb_substr($logs, 0, 1500));
    }
}
