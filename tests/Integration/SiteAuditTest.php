<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Integration;

use BharatSeo\Core\ValidationException;
use BharatSeo\Crawler\HttpResponse;
use BharatSeo\Services\Audit\PageAnalyzer;
use BharatSeo\Services\ClientService;
use BharatSeo\Services\SiteAuditService;
use BharatSeo\Services\SsoService;
use BharatSeo\Security\Gate;
use BharatSeo\Tests\Support\FakeFetcher;
use BharatSeo\Tests\Support\TestEnv;
use BharatSeo\Tests\TestCase;

final class SiteAuditTest extends TestCase
{
    private static array $ids;

    public static function setUpBeforeClass(): void
    {
        TestEnv::freshApp();
        self::$ids = TestEnv::seed();
    }

    private static function page(string $title, string $body, string $extraHead = ''): string
    {
        return '<!doctype html><html lang="en"><head><title>' . $title . '</title><meta name="viewport" content="width=device-width"><meta name="description" content="' . str_repeat('Helpful description about organic farming. ', 3) . '"><link rel="canonical" href="{SELF}">' . $extraHead . '</head><body><h1>Heading</h1>' . $body . '</body></html>';
    }

    private function site(): FakeFetcher
    {
        $words = str_repeat('<p>Organic farming compost soil health guide for Indian growers with practical steps.</p>', 40);
        $nav = '<a href="/guides/">Guides</a> <a href="/about/">About</a> <a href="/old-page/">Old</a> <a href="/Moved_Page/">Moved</a> <a href="https://external.example/">Ext</a>';
        $f = new FakeFetcher();
        $home = str_replace('{SELF}', 'https://greenfarm.in/', self::page('Green Farm – Organic farming in India', $nav . $words, '<script type="application/ld+json">{}</script><meta property="og:title" content="x">'));
        $f->on('https://greenfarm.in/', FakeFetcher::html('https://greenfarm.in/', $home));
        $f->on('http://greenfarm.in/', FakeFetcher::html('http://greenfarm.in/', $home, 200, ['http://greenfarm.in/'], 'https://greenfarm.in/'));
        $f->on('https://www.greenfarm.in/', FakeFetcher::html('https://www.greenfarm.in/', $home, 200, ['https://www.greenfarm.in/'], 'https://greenfarm.in/'));
        $f->on('https://greenfarm.in/robots.txt', new HttpResponse('https://greenfarm.in/robots.txt', 'https://greenfarm.in/robots.txt', 200, ['content-type' => 'text/plain'], "User-agent: *\nDisallow: /admin\nSitemap: https://greenfarm.in/sitemap.xml\n"));
        $f->on('https://greenfarm.in/sitemap.xml', new HttpResponse('https://greenfarm.in/sitemap.xml', 'https://greenfarm.in/sitemap.xml', 200, ['content-type' => 'application/xml'], '<urlset><url><loc>https://greenfarm.in/</loc></url><url><loc>https://greenfarm.in/guides/</loc></url></urlset>'));
        // Duplicate title + thin + image without alt + mixed content
        $f->on('https://greenfarm.in/guides/', FakeFetcher::html('https://greenfarm.in/guides/', str_replace('{SELF}', 'https://greenfarm.in/guides/', self::page('Green Farm – Organic farming in India', '<p>Short page.</p><img src="http://cdn.example/a.jpg"><a href="/">Home</a>'))));
        // No title, no description, two H1s, noindex, not in sitemap
        $f->on('https://greenfarm.in/about/', FakeFetcher::html('https://greenfarm.in/about/', '<html><head><meta name="robots" content="noindex"></head><body><h1>A</h1><h1>B</h1>' . $words . '</body></html>'));
        $f->on('https://greenfarm.in/old-page/', FakeFetcher::status('https://greenfarm.in/old-page/', 404));
        $f->on('https://greenfarm.in/Moved_Page/', FakeFetcher::html('https://greenfarm.in/Moved_Page/', $home, 200, ['https://greenfarm.in/Moved_Page/'], 'https://greenfarm.in/'));
        return $f;
    }

    public function testRequiresVerifiedOwnershipAndManagePermission(): void
    {
        $svc = TestEnv::app()->make(SiteAuditService::class);
        $this->assertThrows(ValidationException::class, fn() => $svc->start(TestEnv::user(self::$ids['manager']), self::$ids['projectA'], []), 'Unverified site cannot be audited');
        TestEnv::db()->update('projects', ['verified_at' => gmdate('Y-m-d H:i:s'), 'verification_method' => 'dns'], 'id = ?', [self::$ids['projectA']]);
        $this->assertThrows(\BharatSeo\Core\HttpException::class, fn() => $svc->start(TestEnv::user(self::$ids['viewer']), self::$ids['projectA'], []), 'Viewers cannot start audits');
        $this->assertThrows(\BharatSeo\Core\HttpException::class, fn() => $svc->start(TestEnv::user(self::$ids['clientA_user']), self::$ids['projectA'], []), 'Clients cannot start audits');
        $this->assertThrows(ValidationException::class, fn() => $svc->start(TestEnv::user(self::$ids['manager']), self::$ids['projectA'], ['start_url' => 'https://other.example/']), 'Start URL must be on the project domain');
    }

    public function testFullAuditFindsIssuesAndScores(): void
    {
        $svc = TestEnv::app()->make(SiteAuditService::class);
        $svc->setFetcher($this->site());
        $id = $svc->start(TestEnv::user(self::$ids['manager']), self::$ids['projectA'], ['max_pages' => 50, 'max_depth' => 3]);
        $this->assertSame(1, (int)TestEnv::db()->fetchColumn("SELECT COUNT(*) FROM job_queue WHERE type = 'site_audit' AND status = 'pending'"));
        $this->assertThrows(ValidationException::class, fn() => $svc->start(TestEnv::user(self::$ids['manager']), self::$ids['projectA'], []), 'Only one running audit per project');
        $done = false;
        for ($i = 0; $i < 5 && !$done; $i++) {
            $done = $svc->processChunk($id, 20);
        }
        $this->assertTrue($done);
        $a = TestEnv::db()->fetch('SELECT * FROM site_audits WHERE id = ?', [$id]);
        $this->assertSame('completed', $a['status']);
        $this->assertSame(5, (int)$a['pages_crawled'], 'Home, guides, about, old-page, Moved_Page (external links not crawled)');
        $pages = [];
        foreach ($svc->pages($id) as $p) {
            $pages[preg_replace('#^https://greenfarm\.in#', '', $p['url'])] = json_decode((string)$p['issues'], true);
        }
        $has = fn(string $path, string $code) => $this->assertTrue(in_array($code, $pages[$path] ?? [], true), "$path should have $code; got " . json_encode($pages[$path] ?? null));
        $has('/old-page/', 'http_4xx');
        $has('/', 'broken_internal_link');
        $has('/', 'internal_redirect');
        $has('/', 'duplicate_title');
        $has('/guides/', 'duplicate_title');
        $has('/guides/', 'thin_content');
        $has('/guides/', 'images_missing_alt');
        $has('/guides/', 'mixed_content');
        $has('/guides/', 'missing_structured_data');
        $has('/about/', 'missing_title');
        $has('/about/', 'missing_meta_description');
        $has('/about/', 'multiple_h1');
        $has('/about/', 'noindex');
        $has('/Moved_Page/', 'url_format');
        $this->assertFalse(in_array('not_in_sitemap', $pages['/guides/'], true), 'Sitemap URLs are recognised');
        $this->assertFalse(in_array('missing_structured_data', $pages['/'], true));
        $checks = json_decode((string)$a['site_checks'], true);
        $this->assertSame([], $checks['issues'], 'HTTPS, redirect, host consolidation, robots and sitemap are fine: ' . json_encode($checks));
        $this->assertTrue((int)$a['score'] > 0 && (int)$a['score'] < 100);
        $this->assertTrue((int)$a['error_count'] >= 5);
        $groups = $svc->issueGroups($a, $svc->pages($id));
        $this->assertSame('error', $groups[0]['severity'], 'Errors are listed first');
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM notifications WHERE type = 'site_audit'"));
        // Client of the project can read the audit; other client cannot.
        $this->assertSame($id, (int)$svc->find(TestEnv::user(self::$ids['clientA_user']), $id)['id']);
        $this->assertThrows(\BharatSeo\Core\HttpException::class, fn() => $svc->find(TestEnv::user(self::$ids['clientB_user']), $id));
        $svc->setFetcher(null);
    }

    public function testSiteLevelProblemsAreReported(): void
    {
        $f = new FakeFetcher();
        $f->on('https://greenfarm.in/', FakeFetcher::html('https://greenfarm.in/', '<html><body><p>hi</p></body></html>'));
        $f->on('http://greenfarm.in/', FakeFetcher::html('http://greenfarm.in/', '<p>hi</p>'));
        $f->on('https://greenfarm.in/robots.txt', new HttpResponse('https://greenfarm.in/robots.txt', 'https://greenfarm.in/robots.txt', 200, ['content-type' => 'text/plain'], "User-agent: *\nDisallow: /\n"));
        $svc = TestEnv::app()->make(SiteAuditService::class);
        $svc->setFetcher($f);
        $c = $svc->siteChecks(['host' => 'greenfarm.in'], 500);
        foreach (['no_https_redirect', 'robots_blocks_all', 'no_sitemap'] as $code) {
            $this->assertTrue(in_array($code, $c['issues'], true), "$code expected: " . json_encode($c['issues']));
        }
        $svc->setFetcher(null);
    }

    public function testScoreFormula(): void
    {
        $this->assertSame(100, PageAnalyzer::score([], [], 10));
        $this->assertSame(90, PageAnalyzer::score(['http_4xx' => 10], [], 10), 'Error on every page costs 10');
        $this->assertSame(99, PageAnalyzer::score(['title_too_long' => 1], [], 10), 'Warning on 1 of 10 pages: 100 − 4 × (0.25 + 0.075) = 98.7');
        $this->assertSame(91, PageAnalyzer::score([], ['no_sitemap', 'host_not_consolidated', 'sitemap_not_in_robots'], 10), '4 + 4 + 1');
    }

    public function testClientAccessCanBeGrantedFromProject(): void
    {
        $admin = TestEnv::user(self::$ids['admin']);
        $svc = TestEnv::app()->make(ClientService::class);
        $uid = $svc->grantAccess($admin, self::$ids['clientA'], ['name' => 'Rita Client', 'email' => 'rita@client-a.test', 'password' => 'Client-Pass-77', 'can_approve' => '1']);
        $row = TestEnv::db()->fetch('SELECT * FROM client_users WHERE user_id = ?', [$uid]);
        $this->assertSame(1, (int)$row['can_approve']);
        $this->assertSame(0, (int)$row['can_export']);
        $gate = TestEnv::app()->make(Gate::class);
        $gate->flush();
        $this->assertSame([self::$ids['projectA']], $gate->projectIds(TestEnv::user($uid)));
        $this->assertThrows(ValidationException::class, fn() => $svc->grantAccess($admin, self::$ids['clientA'], ['email' => 'manager@agency.test', 'name' => 'x', 'password' => 'Whatever-123']), 'Staff accounts cannot be turned into client access');
        $this->assertThrows(\BharatSeo\Core\HttpException::class, fn() => $svc->grantAccess(TestEnv::user(self::$ids['manager']), self::$ids['clientA'], ['user_id' => $uid]), 'Only Super Admins grant client access');
        $same = $svc->grantAccess($admin, self::$ids['clientA'], ['email' => 'rita@client-a.test', 'can_export' => '1']);
        $this->assertSame($uid, $same, 'Existing client user is re-used, not duplicated');
    }

    public function testSsoTokenRules(): void
    {
        $s = TestEnv::app()->settings();
        $sso = TestEnv::app()->make(SsoService::class);
        $this->assertFalse($sso->isEnabled());
        $s->set('sso.website_enabled', true);
        $s->set('sso.website_url', 'https://greenfarm.in');
        $s->set('sso.secret', str_repeat('z', 48));
        $this->assertTrue($sso->allowed(TestEnv::user(self::$ids['manager'])));
        $this->assertFalse($sso->allowed(TestEnv::user(self::$ids['clientA_user'])), 'Clients never get website admin');
        $s->set('sso.roles', 'super_admin');
        $this->assertFalse($sso->allowed(TestEnv::user(self::$ids['manager'])));
        $this->assertTrue($sso->allowed(TestEnv::user(self::$ids['admin'])));
        $t = $sso->tokenFor(TestEnv::user(self::$ids['admin']));
        $claims = SsoService::verify($t, str_repeat('z', 48), 'greenfarm.in');
        $this->assertSame('root@agency.test', $claims['email']);
        $this->assertSame(32, strlen($claims['nonce']));
        $this->assertSame(null, SsoService::verify($t, str_repeat('y', 48), 'greenfarm.in'));
        $this->assertSame(null, SsoService::verify($t, str_repeat('z', 48), 'greenfarm.in', time() + 120));
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM audit_logs WHERE action = 'sso.website_login'"));
        $s->set('sso.website_enabled', false);
    }
}
