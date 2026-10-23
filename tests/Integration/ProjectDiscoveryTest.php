<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Integration;

use BharatSeo\Core\ValidationException;
use BharatSeo\Crawler\HttpClient;
use BharatSeo\Services\DiscoveryService;
use BharatSeo\Services\ProjectService;
use BharatSeo\Services\QueueService;
use BharatSeo\Tests\Support\FakeFetcher;
use BharatSeo\Tests\Support\TestEnv;
use BharatSeo\Tests\TestCase;

final class ProjectDiscoveryTest extends TestCase
{
    private static array $ids;

    public static function setUpBeforeClass(): void
    {
        TestEnv::freshApp();
        self::$ids = TestEnv::seed();
    }

    private function projects(): ProjectService
    {
        return TestEnv::app()->make(ProjectService::class);
    }

    public function testProjectCrudWithValidationAndUniqueDomain(): void
    {
        $admin = TestEnv::user(self::$ids['admin']);
        $input = ['client_id' => self::$ids['clientA'], 'name' => 'Spice Store', 'website_url' => 'HTTPS://WWW.Spices.example/', 'keywords' => "turmeric\ncardamom, pepper", 'topics' => 'indian cooking', 'target_pages' => "https://spices.example/turmeric/\nhttps://www.spices.example/pepper", 'approval_policy' => 'manager_and_client'];
        $id = $this->projects()->create($admin, $input);
        $p = TestEnv::project($id);
        $this->assertSame('spices.example', $p['domain']);
        $this->assertSame(32, strlen($p['verification_token']));
        $this->assertSame(['cardamom', 'pepper', 'turmeric'], $this->projects()->keywords($id, 'keyword'));
        $this->assertSame(2, count($this->projects()->targetPages($id)));
        // Same normalized domain for the same client is rejected.
        $this->assertThrows(ValidationException::class, fn() => $this->projects()->create($admin, ['website_url' => 'http://spices.example'] + $input));
        // Target pages must be on the project domain.
        $this->assertThrows(ValidationException::class, fn() => $this->projects()->create($admin, ['website_url' => 'https://herbs.example', 'target_pages' => 'https://other.example/x'] + $input));
        // Update: changing the domain resets ownership verification.
        TestEnv::db()->update('projects', ['verified_at' => gmdate('Y-m-d H:i:s'), 'verification_method' => 'file'], 'id = ?', [$id]);
        $this->projects()->update($admin, $id, ['website_url' => 'https://masala.example/', 'target_pages' => ''] + $input);
        $p2 = TestEnv::project($id);
        $this->assertSame('masala.example', $p2['domain']);
        $this->assertSame(null, $p2['verified_at']);
        $this->assertNotSame($p['verification_token'], $p2['verification_token']);
        // Pausing cancels pending jobs for the project.
        TestEnv::app()->make(QueueService::class)->push('evaluate_prospect', ['prospect_id' => 999], $id, 'evaluate:999');
        $this->projects()->setStatus($admin, $id, 'paused');
        $this->assertSame('cancelled', TestEnv::db()->fetchColumn('SELECT status FROM job_queue WHERE project_id = ?', [$id]));
    }

    public function testManagerCannotCreateProjectForUnassignedClient(): void
    {
        $m = TestEnv::user(self::$ids['manager']);
        $this->assertThrows(ValidationException::class, fn() => $this->projects()->create($m, ['client_id' => self::$ids['clientB'], 'name' => 'X', 'website_url' => 'https://x-new.example']));
        $id = $this->projects()->create($m, ['client_id' => self::$ids['clientA'], 'name' => 'Allowed', 'website_url' => 'https://allowed.example']);
        $this->assertTrue((bool)TestEnv::db()->fetchColumn('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?', [$id, self::$ids['manager']]), 'Creator becomes a member');
    }

    public function testOwnershipVerificationMethods(): void
    {
        $admin = TestEnv::user(self::$ids['admin']);
        $pid = self::$ids['projectA'];
        $p = TestEnv::project($pid);
        $ins = $this->projects()->verificationInstructions($p);
        $fake = (new FakeFetcher())->on($ins['file']['url'], FakeFetcher::html($ins['file']['url'], 'wrong'), FakeFetcher::html($ins['file']['url'], $ins['file']['content'] . "\n"));
        $this->assertFalse($this->projects()->verifyOwnership($admin, $pid, 'file', $fake)['ok']);
        $this->assertSame(null, TestEnv::project($pid)['verified_at']);
        $this->assertThrows(ValidationException::class, fn() => $this->projects()->importSitemap($admin, $pid, $fake), 'Site crawling features require verification');
        $this->assertTrue($this->projects()->verifyOwnership($admin, $pid, 'file', $fake)['ok']);
        $this->assertTrue(TestEnv::project($pid)['verified_at'] !== null);
        $dns = fn(string $h): array => [['txt' => 'bharatseo-verification=' . $p['verification_token']]];
        $this->assertTrue($this->projects()->verifyOwnership($admin, $pid, 'dns', null, $dns)['ok']);
        $sitemap = '<?xml version="1.0"?><urlset><url><loc>https://greenfarm.in/a/</loc></url><url><loc>https://evil.example/x</loc></url><url><loc>https://greenfarm.in/sitemap-2.xml</loc></url></urlset>';
        $fake->on('https://greenfarm.in/sitemap.xml', new \BharatSeo\Crawler\HttpResponse('https://greenfarm.in/sitemap.xml', 'https://greenfarm.in/sitemap.xml', 200, ['content-type' => 'application/xml'], $sitemap));
        $this->assertSame(1, $this->projects()->importSitemap($admin, $pid, $fake), 'Only same-domain, non-sitemap URLs are imported');
    }

    public function testManualDiscoveryPreventsDuplicatesAndHonoursExclusions(): void
    {
        $m = TestEnv::user(self::$ids['manager']);
        TestEnv::db()->update('projects', ['exclusions' => "blocked.example\n"], 'id = ?', [self::$ids['projectA']]);
        $res = TestEnv::app()->make(DiscoveryService::class)->addManual($m, self::$ids['projectA'], [
            'category' => 'resource_page',
            'urls' => implode("\n", [
                'https://www.soilguide.example/resources/',
                'http://soilguide.example/resources',
                'https://soilguide.example/resources/?utm_source=newsletter',
                'https://greenfarm.in/blog/',              // own site
                'https://sub.blocked.example/page',        // excluded
                'not a url',
                'http://127.0.0.1/admin',                   // IP host
                'https://compost.example/links',
            ]),
        ]);
        $this->assertSame(8, $res['total']);
        $this->assertSame(2, $res['created']);
        $this->assertSame(2, $res['duplicate']);
        $this->assertSame(4, $res['rejected']);
        $run = TestEnv::db()->fetch('SELECT * FROM discovery_runs WHERE id = ?', [$res['run_id']]);
        $this->assertSame('completed', $run['status']);
        $this->assertSame(2, (int)$run['created_count']);
        $this->assertSame(2, (int)TestEnv::db()->fetchColumn("SELECT COUNT(*) FROM job_queue WHERE type = 'evaluate_prospect' AND status = 'pending' AND project_id = ?", [self::$ids['projectA']]), 'Each new prospect gets exactly one evaluation job');
        // Re-submitting is idempotent.
        $again = TestEnv::app()->make(DiscoveryService::class)->addManual($m, self::$ids['projectA'], ['urls' => 'https://soilguide.example/resources/']);
        $this->assertSame(0, $again['created']);
        $this->assertSame(1, $again['duplicate']);
    }

    public function testCompetitorCsvImportUtf16TabDelimitedInChunks(): void
    {
        $m = TestEnv::user(self::$ids['manager']);
        $rows = ["Referring page title\tReferring page URL\tTarget URL\tAnchor\tDomain rating"];
        for ($i = 1; $i <= 230; $i++) {
            $rows[] = "Guide $i\thttps://site$i.example/page\thttps://competitor.example/\tbest compost $i\t30";
        }
        $rows[] = "Dup\thttps://site1.example/page/\thttps://competitor.example/\tx\t1";
        $rows[] = "Formula\t=HYPERLINK(\"http://x\")\thttps://competitor.example/\tx\t1";
        $csv = "\xFF\xFE" . mb_convert_encoding(implode("\r\n", $rows) . "\r\n", 'UTF-16LE', 'UTF-8');
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, $csv);
        $file = ['name' => 'ahrefs-backlinks.csv', 'tmp_name' => $tmp, 'size' => strlen($csv), 'error' => UPLOAD_ERR_OK, 'type' => 'text/csv'];
        $svc = TestEnv::app()->make(DiscoveryService::class);
        $importId = $svc->queueImport($m, self::$ids['projectA'], $file, 'competitor_backlinks', null);
        $this->assertFalse($svc->processImportChunk($importId), 'First chunk is bounded (200 rows)');
        $this->assertTrue($svc->processImportChunk($importId), 'Second chunk finishes');
        $job = TestEnv::db()->fetch('SELECT * FROM import_jobs WHERE id = ?', [$importId]);
        $this->assertSame('completed', $job['status']);
        $this->assertSame(232, (int)$job['total_rows']);
        $this->assertSame(230, (int)$job['created_rows']);
        $this->assertSame(1, (int)$job['duplicate_rows']);
        $this->assertSame(1, (int)$job['error_rows']);
        $p = TestEnv::db()->fetch("SELECT * FROM prospect_pages WHERE normalized_url = 'https://site7.example/page'");
        $this->assertSame('competitor_export', $p['discovery_source']);
        $this->assertContains('anchor “best compost 7”', (string)$p['evidence']);
        $this->assertSame('Guide 7', $p['title']);
        // Same file again is rejected.
        file_put_contents($tmp, $csv);
        $this->assertThrows(ValidationException::class, fn() => $svc->queueImport($m, self::$ids['projectA'], $file, 'competitor_backlinks', null));
    }

    public function testUploadValidationRejectsNonCsv(): void
    {
        $m = TestEnv::user(self::$ids['manager']);
        $tmp = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($tmp, "<?php echo 'x';");
        $svc = TestEnv::app()->make(DiscoveryService::class);
        $this->assertThrows(ValidationException::class, fn() => $svc->queueImport($m, self::$ids['projectA'], ['name' => 'evil.csv', 'tmp_name' => $tmp, 'size' => 15, 'error' => 0], 'prospects', null));
        $this->assertThrows(ValidationException::class, fn() => $svc->queueImport($m, self::$ids['projectA'], ['name' => 'evil.php', 'tmp_name' => $tmp, 'size' => 15, 'error' => 0], 'prospects', null));
        file_put_contents($tmp, str_repeat('a', 100));
        TestEnv::app()->settings()->set('security.upload_max_mb', 1);
        $this->assertThrows(ValidationException::class, fn() => $svc->queueImport($m, self::$ids['projectA'], ['name' => 'big.csv', 'tmp_name' => $tmp, 'size' => 2 * 1048576, 'error' => 0], 'prospects', null));
    }

    public function testPublicPageExtractionRespectsResultSafety(): void
    {
        $m = TestEnv::user(self::$ids['manager']);
        $app = TestEnv::app();
        $svc = $app->make(DiscoveryService::class);
        $runId = $svc->queuePublicPage($m, self::$ids['projectA'], ['page_url' => 'https://www.krishihub.example/resources/organic-farming/', 'category' => 'resource_page']);
        $html = (string)file_get_contents(dirname(__DIR__) . '/fixtures/referring_page.html');
        $fake = (new FakeFetcher())->on('https://www.krishihub.example/resources/organic-farming/', FakeFetcher::html('https://www.krishihub.example/resources/organic-farming/', $html));
        $app->bind(HttpClient::class, fn() => $fake);
        $job = TestEnv::db()->fetch("SELECT * FROM job_queue WHERE type = 'extract_public_page' AND unique_key = ?", ['public_page:' . $runId]);
        $job['data'] = json_decode($job['payload'], true);
        (new \BharatSeo\Jobs\ExtractPublicPageJob($app))->handle($job);
        $urls = array_column(TestEnv::db()->fetchAll('SELECT normalized_url FROM prospect_pages WHERE discovery_run_id = ?', [$runId]), 'normalized_url');
        $this->assertTrue(in_array('https://soil.example.org/health', $urls, true));
        $this->assertTrue(in_array('http://agri.example.net/rotation', $urls, true));
        $this->assertFalse(in_array('https://hidden-casino.example/', $urls, true), 'Hidden links are never harvested');
        $this->assertFalse(in_array('https://ads.example.com/', $urls, true), 'Sidebar links are skipped');
        foreach ($urls as $u) {
            $this->assertFalse(str_contains($u, 'greenfarm.in'), 'Own-domain links are skipped');
        }
        $app->forget(HttpClient::class);
        $app->bind(HttpClient::class, fn($a) => new HttpClient($a));
    }
}
