<?php
declare(strict_types=1);

namespace Flatmates\Tests\Integration;

use Flatmates\Core\App;
use Flatmates\Services\ListingService;
use Flatmates\Services\Migrator;
use Flatmates\Services\TrustService;
use Flatmates\Tests\Support\TestEnv;
use Flatmates\Tests\TestCase;

final class TrustAndMigrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestEnv::freshApp();
    }

    public static function tearDownAfterClass(): void
    {
        TestEnv::cleanupStorage();
    }

    private function input(array $o = []): array
    {
        return $o + ['type' => 'shared_room', 'title' => 'Shared room in a quiet 2BHK', 'city_id' => '1', 'locality' => 'Sector 62', 'rent' => '7000', 'deposit' => '7000',
            'description' => 'Quiet shared room in a well kept flat near the metro, ideal for a student.'];
    }

    public function testUserBadgesComeOnlyFromRealRecords(): void
    {
        $plain = TestEnv::student('t-plain@example.com');
        $unverified = TestEnv::student('t-unv@example.com');
        TestEnv::db()->run('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$unverified]);
        $college = TestEnv::student('t-college@example.com');
        TestEnv::db()->insert('verifications', ['user_id' => $college, 'method' => 'college_email', 'status' => 'approved']);
        $doc = TestEnv::student('t-doc@example.com');
        TestEnv::db()->insert('verifications', ['user_id' => $doc, 'method' => 'document', 'status' => 'approved']);
        $pending = TestEnv::student('t-pending@example.com');
        TestEnv::db()->insert('verifications', ['user_id' => $pending, 'method' => 'document', 'status' => 'pending']);
        $rejected = TestEnv::student('t-rej@example.com');
        TestEnv::db()->insert('verifications', ['user_id' => $rejected, 'method' => 'college_email', 'status' => 'rejected']);
        $b = App::get()->make(TrustService::class)->forUsers([$plain, $unverified, $college, $doc, $pending, $rejected]);
        $this->assertSame(['email_verified'], $b[$plain]);
        $this->assertSame([], $b[$unverified]);
        $this->assertSame(['email_verified', 'college_email'], $b[$college]);
        $this->assertSame(['email_verified', 'student_id'], $b[$doc]);
        $this->assertSame(['email_verified'], $b[$pending], 'pending review is not a badge');
        $this->assertSame(['email_verified'], $b[$rejected]);
        $this->assertTrue(TrustService::isStudentVerified($b[$doc]));
        $this->assertFalse(TrustService::isStudentVerified($b[$plain]));
        // badges reach the deck and public profile
        $viewer = TestEnv::student('t-viewer@example.com');
        $card = array_values(array_filter(App::get()->make(\Flatmates\Services\MatchService::class)->deck(TestEnv::user($viewer)), static fn($c) => $c['user_id'] === $college))[0];
        $this->assertSame(['email_verified', 'college_email'], $card['badges']);
        $this->assertSame(['email_verified', 'student_id'], App::get()->make(\Flatmates\Services\ProfileService::class)->viewFor(TestEnv::user($viewer), $doc)['badges']);
    }

    public function testListingReviewedOnlyAfterModeratorApprovalOfCurrentVersion(): void
    {
        $ls = App::get()->make(ListingService::class);
        $owner = TestEnv::student('t-owner@example.com');
        $mod = TestEnv::makeUser('t-mod@example.com', 'moderator', ['permissions' => 'listings']);
        $id = $ls->create(TestEnv::user($owner), $this->input(), true);
        $row = fn() => TestEnv::db()->fetch('SELECT status, reviewed_at, reviewed_by FROM listings WHERE id = ?', [$id]);
        $this->assertSame(null, $row()['reviewed_at']);
        $ls->moderate(TestEnv::user($mod), $id, 'approve');
        $this->assertTrue($row()['reviewed_at'] !== null);
        $this->assertSame($mod, (int)$row()['reviewed_by']);
        $this->assertSame(['listing_reviewed'], $ls->search(['city_id' => 1])['rows'][0]['badges']);
        // Owner edits → review no longer applies, goes back to the queue
        $ls->update(TestEnv::user($owner), $id, $this->input(['rent' => '7500']), false);
        $this->assertSame(null, $row()['reviewed_at']);
        $this->assertSame('pending', $row()['status']);
        $ls->moderate(TestEnv::user($mod), $id, 'approve');
        $ls->addPhotos(TestEnv::user($owner), $id, [TestEnv::upload(TestEnv::jpeg(900, 700))]);
        $this->assertSame(null, $row()['reviewed_at'], 'new photos clear the review');
        // Thumbnail variant generated
        $path = (string)TestEnv::db()->fetchColumn('SELECT path FROM listing_photos WHERE listing_id = ?', [$id]);
        $this->assertTrue(is_file(App::get()->config->storagePath('uploads/' . substr($path, 0, -4) . '-sm.jpg')));
        $this->assertSame(\Flatmates\Services\ImageService::THUMB, getimagesize(App::get()->config->storagePath('uploads/' . substr($path, 0, -4) . '-sm.jpg'))[0]);
        // With review switched off, listings publish but never get the badge
        App::get()->settings()->set('rules.listing_review', false);
        $id2 = $ls->create(TestEnv::user($owner), $this->input(['title' => 'Vacant bed near the campus gate']), true);
        $l2 = TestEnv::db()->fetch('SELECT status, reviewed_at FROM listings WHERE id = ?', [$id2]);
        $this->assertSame('published', $l2['status']);
        $this->assertSame(null, $l2['reviewed_at']);
        App::get()->settings()->set('rules.listing_review', true);
    }

    public function testMigratorAppliesPendingMigrationsToOldDatabases(): void
    {
        $m = App::get()->make(Migrator::class);
        $this->assertSame([], $m->migrate(), 'fresh schema already includes 002');
        // Simulate a v1 database (deployed before this upgrade)
        $db = TestEnv::db();
        $db->pdo()->exec('ALTER TABLE listings DROP INDEX idx_listing_public, DROP COLUMN reviewed_at, DROP COLUMN reviewed_by');
        $db->run("DELETE FROM schema_migrations WHERE version = '002_trust_reviews'");
        $this->assertSame(null, $m->status()['002_trust_reviews']);
        $this->assertSame(['002_trust_reviews'], $m->migrate());
        $this->assertSame(1, (int)$db->fetchColumn("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'listings' AND column_name = 'reviewed_at'"));
        $this->assertTrue($m->status()['002_trust_reviews'] !== null);
        $this->assertSame([], $m->migrate(), 'idempotent');
    }
}
