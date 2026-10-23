<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Integration;

use BharatSeo\Crawler\HttpClient;
use BharatSeo\Services\BacklinkMonitor;
use BharatSeo\Services\BacklinkService;
use BharatSeo\Tests\Support\FakeFetcher;
use BharatSeo\Tests\Support\TestEnv;
use BharatSeo\Tests\TestCase;

final class BacklinkMonitorTest extends TestCase
{
    private const REF = 'https://www.krishihub.example/resources/organic-farming/';
    private const TARGET = 'https://greenfarm.in/guides/composting/';
    private static array $ids;
    private static FakeFetcher $fake;
    private static string $html;

    public static function setUpBeforeClass(): void
    {
        $app = TestEnv::freshApp();
        self::$ids = TestEnv::seed();
        self::$fake = new FakeFetcher();
        $app->bind(HttpClient::class, fn() => self::$fake);
        $app->forget(BacklinkMonitor::class);
        self::$html = (string)file_get_contents(dirname(__DIR__) . '/fixtures/referring_page.html');
    }

    private function record(string $ref = self::REF, string $target = self::TARGET): int
    {
        $r = TestEnv::app()->make(BacklinkService::class)->createRecord(TestEnv::project(self::$ids['projectA']), $ref, $target, 'Home composting guide by Green Farm', 'manual', null, null);
        return (int)$r['id'];
    }

    private function check(int $id): string
    {
        return TestEnv::app()->make(BacklinkMonitor::class)->check($id);
    }

    private function row(int $id): array
    {
        return TestEnv::db()->fetch('SELECT * FROM backlink_records WHERE id = ?', [$id]);
    }

    /** Simulate time passing so the next negative observation counts as a spaced confirmation. */
    private function age(int $id, int $hours = 7): void
    {
        TestEnv::db()->run('UPDATE backlink_records SET last_checked_at = UTC_TIMESTAMP() - INTERVAL ? HOUR WHERE id = ?', [$hours, $id]);
    }

    public function testDuplicateTrackingPrevented(): void
    {
        $this->record('https://dupe.example/a');
        $again = TestEnv::app()->make(BacklinkService::class)->createRecord(TestEnv::project(self::$ids['projectA']), 'http://www.dupe.example/a/', self::TARGET, null, 'manual', null, null);
        $this->assertSame('duplicate', $again['status']);
        $own = TestEnv::app()->make(BacklinkService::class)->createRecord(TestEnv::project(self::$ids['projectA']), 'https://greenfarm.in/blog', self::TARGET, null, 'manual', null, null);
        $this->assertSame('rejected', $own['status']);
    }

    public function testFullLifecycleWithRetryAndConfirmationRules(): void
    {
        $id = $this->record();
        $this->assertSame('unverified', $this->row($id)['status']);
        $this->assertSame('candidate', $this->row($id)['lifecycle']);

        // 1. Link observed → active + confirmed.
        self::$fake->on(self::REF, FakeFetcher::html(self::REF, self::$html));
        $this->assertSame('active', $this->check($id));
        $r = $this->row($id);
        $this->assertSame('confirmed', $r['lifecycle']);
        $this->assertSame(1, (int)$r['link_present']);
        $this->assertTrue($r['first_detected_at'] !== null && $r['last_verified_at'] !== null);
        $firstDetected = $r['first_detected_at'];

        // 2. A single temporary error never marks it lost.
        $this->age($id);
        self::$fake->on(self::REF, FakeFetcher::status(self::REF, 503));
        $this->assertSame('temporarily_unavailable', $this->check($id));
        $this->assertSame('confirmed', $this->row($id)['lifecycle']);

        // 3. Recovery → active again, counters reset, first detection preserved.
        $this->age($id);
        self::$fake->on(self::REF, FakeFetcher::html(self::REF, self::$html));
        $this->assertSame('active', $this->check($id));
        $this->assertSame(0, (int)$this->row($id)['consecutive_failures']);
        $this->assertSame($firstDetected, $this->row($id)['first_detected_at']);

        // 4. Link removed: first negative check keeps status (pending confirmation).
        $this->age($id);
        $removed = '<html><body><article><p>Our resources page was redesigned.</p></article></body></html>';
        self::$fake->on(self::REF, FakeFetcher::html(self::REF, $removed));
        $this->assertSame('active', $this->check($id));
        $this->assertSame('missing', $this->row($id)['pending_observation']);
        $this->assertSame(1, (int)$this->row($id)['consecutive_negative']);

        // 5. An immediate re-check (too soon) does NOT count as confirmation.
        $this->assertSame('active', $this->check($id));
        $this->assertSame(1, (int)$this->row($id)['consecutive_negative']);

        // 6. A spaced second negative confirms → lost.
        $this->age($id);
        $this->assertSame('lost', $this->check($id));
        $this->assertSame('lost', $this->row($id)['lifecycle']);
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM notifications WHERE type = 'backlink_lost'"));

        // 7. Re-detected later → confirmed again.
        $this->age($id);
        self::$fake->on(self::REF, FakeFetcher::html(self::REF, self::$html));
        $this->assertSame('active', $this->check($id));
        $this->assertSame('confirmed', $this->row($id)['lifecycle']);

        // History keeps every observation, flags each status change.
        $history = TestEnv::db()->fetchAll('SELECT * FROM backlink_check_history WHERE backlink_id = ? ORDER BY id', [$id]);
        $this->assertSame(7, count($history));
        $changes = array_values(array_filter($history, static fn($h) => (int)$h['status_changed'] === 1));
        $this->assertSame(['unverified>active', 'active>temporarily_unavailable', 'temporarily_unavailable>active', 'active>lost', 'lost>active'], array_map(static fn($h) => $h['status_before'] . '>' . $h['status_after'], $changes));
        $this->assertContains('confirmation_too_soon', $history[4]['observation']);
    }

    public function testRepeatedTemporaryErrorsEventuallyMarkBroken(): void
    {
        $ref = 'https://flaky-host.example/links';
        $id = $this->record($ref);
        self::$fake->on($ref, FakeFetcher::html($ref, '<a href="' . self::TARGET . '">Home composting guide by Green Farm</a>'));
        $this->check($id);
        self::$fake->on($ref, FakeFetcher::networkError($ref));
        foreach (['temporarily_unavailable', 'temporarily_unavailable', 'broken'] as $expected) {
            $this->age($id);
            $this->assertSame($expected, $this->check($id));
        }
        $this->assertSame('lost', $this->row($id)['lifecycle']);
    }

    public function testCandidateNeverObservedStaysUnverified(): void
    {
        $ref = 'https://notyet.example/page';
        $id = $this->record($ref);
        self::$fake->on($ref, FakeFetcher::html($ref, '<p>nothing yet</p>'));
        $this->assertSame('unverified', $this->check($id));
        $this->age($id);
        $this->assertSame('unverified', $this->check($id), 'A link that was never observed cannot be "lost"');
        $this->assertSame('candidate', $this->row($id)['lifecycle']);
    }

    public function testAttributeAndTargetChanges(): void
    {
        $ref = 'https://changing.example/p';
        $id = $this->record($ref);
        self::$fake->on($ref, FakeFetcher::html($ref, '<a href="' . self::TARGET . '" rel="nofollow">Home composting guide by Green Farm</a>'));
        $this->assertSame('nofollow', $this->check($id));
        $this->assertSame(1, (int)$this->row($id)['is_nofollow']);
        $this->age($id);
        self::$fake->on($ref, FakeFetcher::html($ref, '<a href="' . self::TARGET . '">Green Farm composting</a>'));
        $this->assertSame('changed_anchor', $this->check($id));
        self::$fake->on($ref, FakeFetcher::html($ref, '<a href="https://greenfarm.in/">Green Farm</a>'));
        $this->age($id);
        $this->assertSame('changed_anchor', $this->check($id), 'First not-pointing observation awaits confirmation');
        $this->age($id);
        $this->assertSame('not_pointing', $this->check($id));
    }

    public function testDueChecksAreEnqueuedIdempotently(): void
    {
        TestEnv::db()->run('UPDATE backlink_records SET next_check_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        TestEnv::db()->run("DELETE FROM job_queue WHERE type = 'check_backlink'");
        $svc = TestEnv::app()->make(BacklinkService::class);
        $n = $svc->enqueueDue(100);
        $this->assertGreaterOrEqual(1, $n);
        $svc->enqueueDue(100);
        $this->assertSame($n, (int)TestEnv::db()->fetchColumn("SELECT COUNT(*) FROM job_queue WHERE type = 'check_backlink' AND status = 'pending'"));
    }
}
