<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Integration;

use BharatSeo\Core\ValidationException;
use BharatSeo\Crawler\HttpClient;
use BharatSeo\Jobs\RetryableException;
use BharatSeo\Services\ApprovalService;
use BharatSeo\Services\EvaluationService;
use BharatSeo\Services\ProspectService;
use BharatSeo\Tests\Support\FakeFetcher;
use BharatSeo\Tests\Support\TestEnv;
use BharatSeo\Tests\TestCase;

final class EvaluationApprovalTest extends TestCase
{
    private static array $ids;
    private static FakeFetcher $fake;

    public static function setUpBeforeClass(): void
    {
        $app = TestEnv::freshApp();
        self::$ids = TestEnv::seed();
        self::$fake = new FakeFetcher();
        $app->bind(HttpClient::class, fn() => self::$fake);
    }

    private function create(string $url, string $category = 'resource_page'): int
    {
        return (int)TestEnv::app()->make(ProspectService::class)->createFromUrl(TestEnv::project(self::$ids['projectA']), $url, ['category' => $category], null, self::$ids['manager'])['id'];
    }

    private function goodPage(string $extra = ''): string
    {
        $p = '';
        for ($i = 0; $i < 40; $i++) {
            $p .= "<p>Section $i covers a different organic farming topic: compost making, soil health testing and mulching for small farms.</p>";
        }
        return '<html><head><title>Organic farming and compost resources</title><meta name="description" content="Soil health resources"><meta name="author" content="Ed"></head><body><nav><a href="/about/">About</a><a href="/contact/">Contact</a></nav><article><h1>Organic farming</h1><time datetime="2026-01-01"></time>'
            . $p . '<a href="https://ext1.example/a">Ext</a><a href="https://ext2.example/b">Ext2</a><p>Contact: editor@goodsite.example</p>' . $extra . '</article></body></html>';
    }

    public function testEvaluationScoresAndMovesToReview(): void
    {
        $id = $this->create('https://goodsite.example/organic-resources/');
        self::$fake->on('https://goodsite.example/organic-resources/', FakeFetcher::html('https://goodsite.example/organic-resources/', $this->goodPage()));
        $r = TestEnv::app()->make(EvaluationService::class)->evaluate($id);
        $p = TestEnv::db()->fetch('SELECT * FROM prospect_pages WHERE id = ?', [$id]);
        $this->assertSame('needs_review', $p['status']);
        $this->assertSame($r['total'], (int)$p['quality_score']);
        $this->assertSame('editor@goodsite.example', $p['contact_email'], 'Publicly listed same-domain email is captured');
        $this->assertTrue(in_array($p['suggested_anchor'], ['Green Farm', 'greenfarm.in', 'Home composting guide'], true), 'Natural brand/URL/title anchor suggested, got ' . $p['suggested_anchor']);
        $this->assertSame(1, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM quality_assessments WHERE prospect_page_id = ? AND is_current = 1', [$id]));
        // Re-evaluation keeps exactly one current assessment (idempotent).
        TestEnv::app()->make(EvaluationService::class)->evaluate($id);
        $this->assertSame(1, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM quality_assessments WHERE prospect_page_id = ? AND is_current = 1', [$id]));
        $this->assertSame(2, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM quality_assessments WHERE prospect_page_id = ?', [$id]));
    }

    public function testTemporaryFetchErrorIsRetriedNotScored(): void
    {
        $id = $this->create('https://flaky.example/page');
        self::$fake->on('https://flaky.example/page', FakeFetcher::status('https://flaky.example/page', 503));
        $this->assertThrows(RetryableException::class, fn() => TestEnv::app()->make(EvaluationService::class)->evaluate($id, null, false));
        $this->assertSame('discovered', TestEnv::db()->fetchColumn('SELECT status FROM prospect_pages WHERE id = ?', [$id]));
        // Final attempt records the outcome honestly.
        TestEnv::app()->make(EvaluationService::class)->evaluate($id, null, true);
        $p = TestEnv::db()->fetch('SELECT * FROM prospect_pages WHERE id = ?', [$id]);
        $this->assertSame('evaluated', $p['status'], 'Low-scoring inaccessible page stays evaluated (low priority)');
        $this->assertSame(503, (int)$p['http_status']);
    }

    public function testCriticalRiskForcesManualHoldAndBlocksApproval(): void
    {
        $id = $this->create('https://riskysite.example/resources/');
        self::$fake->on('https://riskysite.example/resources/', FakeFetcher::html('https://riskysite.example/resources/', $this->goodPage('<div style="display:none"><a href="https://pills.example/">x</a></div>')));
        $r = TestEnv::app()->make(EvaluationService::class)->evaluate($id);
        $this->assertTrue($r['has_critical']);
        $p = TestEnv::db()->fetch('SELECT * FROM prospect_pages WHERE id = ?', [$id]);
        $this->assertSame(1, (int)$p['manual_hold']);
        $this->assertSame('needs_review', $p['status']);
        $mgr = TestEnv::user(self::$ids['manager']);
        $approvals = TestEnv::app()->make(ApprovalService::class);
        $this->assertThrows(ValidationException::class, fn() => $approvals->decideProspect($mgr, $id, true, null), 'Manual hold blocks approval even with a high score');
        $this->assertThrows(ValidationException::class, fn() => TestEnv::app()->make(ProspectService::class)->releaseHold($mgr, $id, 'ok'), 'A meaningful reason is required');
        TestEnv::app()->make(ProspectService::class)->releaseHold($mgr, $id, 'Hidden link is a cookie banner artefact; verified manually.');
        $approvals->decideProspect($mgr, $id, true, 'Reviewed');
        $this->assertSame('approved', TestEnv::db()->fetchColumn('SELECT status FROM prospect_pages WHERE id = ?', [$id]));
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM audit_logs WHERE action = 'prospect.hold_released' AND entity_id = ?", [$id]));
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM approval_requests WHERE entity_type = 'prospect' AND entity_id = ? AND status = 'approved'", [$id]));
    }

    public function testRejectionWorkflowAndPermissions(): void
    {
        $id = $this->create('https://meh.example/');
        TestEnv::app()->make(ProspectService::class)->transition($id, 'needs_review', null);
        $approvals = TestEnv::app()->make(ApprovalService::class);
        $this->assertThrows(ValidationException::class, fn() => $approvals->decideProspect(TestEnv::user(self::$ids['manager']), $id, false, ''), 'Rejection needs a reason');
        $this->assertThrows(\BharatSeo\Core\HttpException::class, fn() => $approvals->decideProspect(TestEnv::user(self::$ids['viewer']), $id, true, null), 'Viewers cannot approve');
        $this->assertThrows(\BharatSeo\Core\HttpException::class, fn() => $approvals->decideProspect(TestEnv::user(self::$ids['manager2']), $id, true, null), 'Other project managers cannot see it');
        $approvals->decideProspect(TestEnv::user(self::$ids['manager']), $id, false, 'Off-topic');
        $p = TestEnv::db()->fetch('SELECT status, status_reason FROM prospect_pages WHERE id = ?', [$id]);
        $this->assertSame('rejected', $p['status']);
        $this->assertSame('Off-topic', $p['status_reason']);
        $this->assertThrows(ValidationException::class, fn() => TestEnv::app()->make(ProspectService::class)->transition($id, 'approved', null), 'Rejected cannot jump to approved');
    }

    public function testManualSubmissionRecordsUniqueExternalId(): void
    {
        $mgr = TestEnv::user(self::$ids['manager']);
        $a = $this->create('https://directory-one.example/listing', 'business_directory');
        $b = $this->create('https://directory-two.example/listing', 'business_directory');
        foreach ([$a, $b] as $id) {
            TestEnv::app()->make(ProspectService::class)->transition($id, 'needs_review', null);
            TestEnv::app()->make(ApprovalService::class)->decideProspect($mgr, $id, true, null);
        }
        TestEnv::app()->make(ProspectService::class)->markSubmitted($mgr, $a, 'LIST-123', null);
        $this->assertSame('submitted', TestEnv::db()->fetchColumn('SELECT status FROM prospect_pages WHERE id = ?', [$a]));
        $this->assertThrows(ValidationException::class, fn() => TestEnv::app()->make(ProspectService::class)->markSubmitted($mgr, $b, 'LIST-123', null), 'External submission ids are unique per project');
    }
}
