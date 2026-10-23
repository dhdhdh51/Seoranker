<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Integration;

use BharatSeo\Services\QueueService;
use BharatSeo\Tests\Support\TestEnv;
use BharatSeo\Tests\TestCase;

final class QueueTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestEnv::freshApp();
        TestEnv::seed();
    }

    public function setUp(): void
    {
        TestEnv::db()->run('DELETE FROM failed_jobs');
        TestEnv::db()->run('DELETE FROM job_queue');
        TestEnv::app()->settings()->set('queue.paused', false);
    }

    private function q(): QueueService
    {
        return TestEnv::app()->make(QueueService::class);
    }

    public function testIdempotentEnqueueWithUniqueKey(): void
    {
        $a = $this->q()->push('evaluate_prospect', ['prospect_id' => 1], null, 'evaluate:1');
        $b = $this->q()->push('evaluate_prospect', ['prospect_id' => 1], null, 'evaluate:1');
        $this->assertSame($a, $b);
        $this->assertSame(1, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM job_queue'));
        $job = $this->q()->reserve('w1');
        $this->q()->complete($job);
        $c = $this->q()->push('evaluate_prospect', ['prospect_id' => 1], null, 'evaluate:1');
        $this->assertNotSame($a, $c, 'After completion the key is released so the work can be re-queued');
    }

    public function testReservationIsExclusive(): void
    {
        $this->q()->push('evaluate_prospect', ['prospect_id' => 11]);
        $first = $this->q()->reserve('worker-A');
        $second = $this->q()->reserve('worker-B');
        $this->assertTrue($first !== null);
        $this->assertSame(null, $second, 'A running job cannot be reserved twice');
        $this->assertSame('running', $first['status']);
        $this->assertSame(1, (int)$first['attempts']);
        // Two jobs → two different workers get different jobs.
        $this->q()->push('evaluate_prospect', ['prospect_id' => 12]);
        $this->q()->push('evaluate_prospect', ['prospect_id' => 13]);
        $x = $this->q()->reserve('worker-C');
        $y = $this->q()->reserve('worker-D');
        $this->assertNotSame($x['id'], $y['id']);
    }

    public function testRetryWithExponentialBackoffThenFailedJobArchive(): void
    {
        $id = $this->q()->push('evaluate_prospect', ['prospect_id' => 999999], null, null, 0, 5, 3);
        // Handler throws PermanentException for missing prospect → immediate failure. Use a retryable path instead:
        $job = $this->q()->reserve('w');
        $this->q()->fail($job, 'Temporary network error', true);
        $row = TestEnv::db()->fetch('SELECT *, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), available_at) AS wait FROM job_queue WHERE id = ?', [$id]);
        $this->assertSame('pending', $row['status']);
        $this->assertGreaterOrEqual(55, (int)$row['wait'], 'First retry waits ~60 s');
        TestEnv::db()->run('UPDATE job_queue SET available_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
        $job = $this->q()->reserve('w');
        $this->q()->fail($job, 'Temporary network error again', true);
        $row = TestEnv::db()->fetch('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), available_at) AS wait FROM job_queue WHERE id = ?', [$id]);
        $this->assertGreaterOrEqual(115, (int)$row['wait'], 'Second retry backs off ~120 s');
        TestEnv::db()->run('UPDATE job_queue SET available_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
        $job = $this->q()->reserve('w');
        $this->q()->fail($job, 'Still failing', true);
        $this->assertSame('failed', TestEnv::db()->fetchColumn('SELECT status FROM job_queue WHERE id = ?', [$id]), 'Max attempts reached');
        $f = TestEnv::db()->fetch('SELECT * FROM failed_jobs WHERE job_id = ?', [$id]);
        $this->assertSame(3, (int)$f['attempts']);
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM notifications WHERE type = 'job_failed'"));
        $this->assertTrue($this->q()->retryFailed((int)$f['id'], 1));
        $this->assertSame('retried', TestEnv::db()->fetchColumn('SELECT resolution FROM failed_jobs WHERE id = ?', [$f['id']]));
        $this->assertSame(1, (int)TestEnv::db()->fetchColumn("SELECT COUNT(*) FROM job_queue WHERE status = 'pending'"));
    }

    public function testPermanentFailureFromHandlerGoesStraightToFailed(): void
    {
        $id = $this->q()->push('evaluate_prospect', ['prospect_id' => 424242], null, null, 0, 5, 5);
        $stats = $this->q()->work('w', 10, 5);
        $this->assertSame(1, $stats['processed']);
        $this->assertSame('failed', TestEnv::db()->fetchColumn('SELECT status FROM job_queue WHERE id = ?', [$id]));
        $this->assertContains('no longer exists', (string)TestEnv::db()->fetchColumn('SELECT error FROM failed_jobs WHERE job_id = ?', [$id]));
    }

    public function testStaleLocksAreRecovered(): void
    {
        $id = $this->q()->push('evaluate_prospect', ['prospect_id' => 5]);
        $this->q()->reserve('crashed-worker');
        TestEnv::db()->run('UPDATE job_queue SET lock_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?', [$id]);
        $this->assertSame(1, $this->q()->recoverStale());
        $this->assertSame('pending', TestEnv::db()->fetchColumn('SELECT status FROM job_queue WHERE id = ?', [$id]));
        $this->assertContains('lock expired', (string)TestEnv::db()->fetchColumn('SELECT last_error FROM job_queue WHERE id = ?', [$id]));
    }

    public function testPauseStopsProcessingImmediately(): void
    {
        $this->q()->push('evaluate_prospect', ['prospect_id' => 6]);
        TestEnv::app()->settings()->set('queue.paused', true);
        $stats = $this->q()->work('w', 5, 5);
        $this->assertTrue($stats['paused']);
        $this->assertSame(0, $stats['processed']);
        $this->assertSame('pending', TestEnv::db()->fetchColumn('SELECT status FROM job_queue LIMIT 1'));
    }

    public function testBackoffCapsAndJitter(): void
    {
        $this->assertGreaterOrEqual(60, QueueService::backoffSeconds(1));
        $this->assertLess(QueueService::BACKOFF_MAX_SECONDS * 1.11, QueueService::backoffSeconds(30));
        $this->assertGreaterOrEqual(3600, QueueService::backoffSeconds(1, 3600), 'Retry-After hint respected');
    }
}
