<?php
declare(strict_types=1);

namespace Flatmates\Tests\Integration;

use Flatmates\Core\App;
use Flatmates\Core\HttpException;
use Flatmates\Core\ValidationException;
use Flatmates\Services\AccountService;
use Flatmates\Services\ChatService;
use Flatmates\Services\CronTasks;
use Flatmates\Services\EnquiryService;
use Flatmates\Services\ListingService;
use Flatmates\Services\Mailer;
use Flatmates\Services\SafetyService;
use Flatmates\Services\SsoReceiver;
use Flatmates\Services\VerificationService;
use Flatmates\Tests\Support\TestEnv;
use Flatmates\Tests\TestCase;

final class ListingVerificationAccountTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestEnv::freshApp();
    }

    public static function tearDownAfterClass(): void
    {
        TestEnv::cleanupStorage();
    }

    private function l(): ListingService
    {
        return App::get()->make(ListingService::class);
    }

    private function input(array $o = []): array
    {
        return $o + ['type' => 'vacant_bed', 'title' => 'Vacant bed in 3BHK near Amity', 'city_id' => '1', 'locality' => 'Sector 125', 'address_private' => 'Flat 402, Tower B, Secret Residency',
            'near_college_id' => '1', 'rent' => '8500', 'deposit' => '8500', 'gender_pref' => 'female', 'furnishing' => 'furnished', 'total_beds' => '3', 'beds_available' => '1',
            'amenities' => ['wifi', 'ac', 'bogus'], 'description' => 'Bright furnished room with two friendly flatmates, ten minutes walk to campus. Rent includes wifi.', 'visibility' => 'public'];
    }

    public function testCreateReviewPublishSearchAndPrivacy(): void
    {
        $owner = TestEnv::student('lst-owner@example.com');
        $id = $this->l()->create(TestEnv::user($owner), $this->input(), true);
        $row = TestEnv::db()->fetch('SELECT * FROM listings WHERE id = ?', [$id]);
        $this->assertSame('pending', $row['status'], 'review on by default');
        $this->assertSame('wifi,ac', $row['amenities']);
        $this->assertContains('vacant-bed-in-3bhk-near-amity-sector-125-' . $id, $row['slug']);
        $this->assertSame(0, $this->l()->search(['city_id' => 1])['total']);
        $this->assertThrows(HttpException::class, fn() => $this->l()->view($row['slug'], null), 'pending invisible to public');
        $mod = TestEnv::makeUser('lst-mod@example.com', 'moderator', ['permissions' => 'listings']);
        $this->assertThrows(HttpException::class, fn() => $this->l()->moderate(TestEnv::user(TestEnv::makeUser('lst-mod2@example.com', 'moderator', ['permissions' => 'reports'])), $id, 'approve'));
        $this->l()->moderate(TestEnv::user($mod), $id, 'approve');
        $this->assertSame(1, $this->l()->search(['city_id' => 1, 'type' => 'vacant_bed', 'max_rent' => 9000, 'amenities' => ['wifi'], 'gender' => 'female'])['total']);
        $this->assertSame(0, $this->l()->search(['city_id' => 1, 'max_rent' => 8000])['total']);
        $this->assertSame(0, $this->l()->search(['amenities' => ['parking']])['total']);
        $this->assertSame(1, $this->l()->search(['q' => '125'])['total']);
        $this->assertSame(0, $this->l()->search(['q' => '%'])['total'], 'LIKE wildcards escaped');
        $public = $this->l()->view($row['slug'], null);
        $this->assertSame(null, $public['address_private'], 'address hidden from public');
        $stranger = TestEnv::student('lst-stranger@example.com');
        $this->assertSame(null, $this->l()->view($row['slug'], TestEnv::user($stranger))['address_private']);
        $this->assertSame('Flat 402, Tower B, Secret Residency', $this->l()->view($row['slug'], TestEnv::user($owner))['address_private']);

        // Enquiry → accept → chat + address revealed
        $enq = App::get()->make(EnquiryService::class);
        $this->assertThrows(ValidationException::class, fn() => $enq->create(TestEnv::user($owner), $id, 'enquiry', 'Hello this is me', null), 'not on own listing');
        $eid = $enq->create(TestEnv::user($stranger), $id, 'viewing', 'Hi, I am a first year student, can I visit?', (new \DateTimeImmutable('+2 days', App::get()->timezone()))->format('Y-m-d\TH:i'));
        $this->assertThrows(ValidationException::class, fn() => $enq->create(TestEnv::user($stranger), $id, 'enquiry', 'Second enquiry here', null), 'one pending at a time');
        $this->assertThrows(HttpException::class, fn() => $enq->respond(TestEnv::user($stranger), $eid, 'accept'), 'only owner responds');
        $conv = $enq->respond(TestEnv::user($owner), $eid, 'accept');
        $this->assertTrue($conv > 0);
        $this->assertSame('Flat 402, Tower B, Secret Residency', $this->l()->view($row['slug'], TestEnv::user($stranger))['address_private']);
        $msgs = App::get()->make(ChatService::class)->messages(TestEnv::user($owner), $conv);
        $this->assertContains('Viewing request', (string)$msgs[0]['body']);
        App::get()->make(ChatService::class)->send(TestEnv::user($owner), $conv, 'Sure, come Saturday');

        // Contact details in descriptions are refused
        $this->assertThrows(ValidationException::class, fn() => $this->l()->create(TestEnv::user($owner), $this->input(['description' => 'Call me on 9876543210 for details about this lovely room please']), false));
        // Owner-only editing
        $this->assertThrows(HttpException::class, fn() => $this->l()->update(TestEnv::user($stranger), $id, $this->input(), false));
        // Edit of published listing goes back to review
        $this->l()->update(TestEnv::user($owner), $id, $this->input(['rent' => '9000']), false);
        $this->assertSame('pending', TestEnv::db()->fetchColumn('SELECT status FROM listings WHERE id = ?', [$id]));
    }

    public function testAutoHideAfterReportsAndListingLimit(): void
    {
        App::get()->settings()->set('rules.listing_review', false);
        $owner = TestEnv::student('ah-owner@example.com');
        $id = $this->l()->create(TestEnv::user($owner), $this->input(), true);
        $this->assertSame('published', TestEnv::db()->fetchColumn('SELECT status FROM listings WHERE id = ?', [$id]));
        $s = App::get()->make(SafetyService::class);
        foreach ([1, 2, 3] as $i) {
            $s->report(TestEnv::user(TestEnv::student("ah-rep$i@example.com")), 'listing', $id, 'fake');
        }
        $this->assertSame('hidden', TestEnv::db()->fetchColumn('SELECT status FROM listings WHERE id = ?', [$id]));
        $this->assertThrows(HttpException::class, fn() => $this->l()->submit(TestEnv::user($owner), $id), 'cannot self-republish a hidden listing');
        App::get()->settings()->set('rules.listings_per_user', 2);
        $this->l()->create(TestEnv::user($owner), $this->input(), false);
        $this->assertThrows(ValidationException::class, fn() => $this->l()->create(TestEnv::user($owner), $this->input(), false));
        App::get()->settings()->set('rules.listings_per_user', 5);
        App::get()->settings()->set('rules.listing_review', true);
    }

    public function testPhotosAreReencodedAndCapped(): void
    {
        $owner = TestEnv::student('ph-owner@example.com');
        $id = $this->l()->create(TestEnv::user($owner), $this->input(), false);
        $files = [];
        for ($i = 0; $i < ListingService::MAX_PHOTOS; $i++) {
            $files[] = TestEnv::upload(TestEnv::jpeg());
        }
        $this->assertSame(ListingService::MAX_PHOTOS, $this->l()->addPhotos(TestEnv::user($owner), $id, $files));
        $this->assertThrows(ValidationException::class, fn() => $this->l()->addPhotos(TestEnv::user($owner), $id, [TestEnv::upload(TestEnv::jpeg())]));
        $p = $this->l()->photos($id)[0];
        $this->assertSame(1, preg_match('#^listings/[a-f0-9]{32}\.jpg$#', $p['path']));
        $this->assertTrue(is_file(App::get()->config->storagePath('uploads/' . $p['path'])));
        // A PHP file disguised as an image is rejected
        $evil = tempnam(sys_get_temp_dir(), 'evil');
        file_put_contents($evil, '<?php echo "pwned";');
        $this->assertThrows(ValidationException::class, fn() => App::get()->make(\Flatmates\Services\ImageService::class)->store(TestEnv::upload($evil, 'x.jpg'), 'photos'));
        $this->l()->delete(TestEnv::user($owner), $id);
        $this->assertFalse(is_file(App::get()->config->storagePath('uploads/' . $p['path'])), 'photo files removed with listing');
    }

    public function testCollegeEmailVerification(): void
    {
        $u = TestEnv::student('ve-stu@example.com', ['college_id' => null]);
        $v = App::get()->make(VerificationService::class);
        $this->assertThrows(ValidationException::class, fn() => $v->startCollegeEmail(TestEnv::user($u), 'me@gmail.com'));
        $this->assertSame('Sharda University', $v->collegeForEmail('x@ug.sharda.ac.in')['name'], 'subdomains accepted');
        $this->assertSame(null, $v->collegeForEmail('x@fakesharda.ac.in'), 'suffix must be a whole domain label');
        $v->startCollegeEmail(TestEnv::user($u), 'Stu@Amity.edu');
        $this->assertSame('stu@amity.edu', TestEnv::mailbox()->last()->toEmail);
        $this->assertThrows(ValidationException::class, fn() => $v->confirmCollegeEmail(TestEnv::user($u), '000000'));
        $v->confirmCollegeEmail(TestEnv::user($u), (string)TestEnv::lastCode());
        $this->assertTrue($v->isVerified($u));
        $this->assertSame(1, (int)TestEnv::db()->fetchColumn('SELECT college_id FROM profiles WHERE user_id = ?', [$u]));
        $other = TestEnv::student('ve-other@example.com');
        $this->assertThrows(ValidationException::class, fn() => $v->startCollegeEmail(TestEnv::user($other), 'stu@amity.edu'), 'one account per college email');
    }

    public function testDocumentVerificationPrivacy(): void
    {
        $u = TestEnv::student('doc-stu@example.com');
        $v = App::get()->make(VerificationService::class);
        $id = $v->submitDocument(TestEnv::user($u), 'college_id', TestEnv::upload(TestEnv::jpeg(600, 400)));
        $path = (string)TestEnv::db()->fetchColumn('SELECT document_path FROM verifications WHERE id = ?', [$id]);
        $this->assertSame(1, preg_match('#^private/verifications/' . $u . '-[a-f0-9]{32}\.jpg$#', $path));
        $abs = App::get()->config->storagePath($path);
        $this->assertTrue(is_file($abs));
        $this->assertThrows(ValidationException::class, fn() => $v->submitDocument(TestEnv::user($u), 'college_id', TestEnv::upload(TestEnv::jpeg())), 'one pending');
        $noPerm = TestEnv::makeUser('doc-mod@example.com', 'moderator', ['permissions' => 'reports']);
        $this->assertThrows(HttpException::class, fn() => $v->document(TestEnv::user($noPerm), $id));
        $mod = TestEnv::makeUser('doc-mod2@example.com', 'moderator', ['permissions' => 'verifications']);
        $doc = $v->document(TestEnv::user($mod), $id);
        $this->assertSame(realpath($abs), $doc['path']);
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM audit_logs WHERE action = 'verification.document_viewed' AND actor_id = ?", [$mod]));
        $this->assertThrows(ValidationException::class, fn() => $v->review(TestEnv::user($mod), $id, 'reject', ''), 'reject needs a note');
        $v->review(TestEnv::user($mod), $id, 'approve');
        $this->assertFalse(is_file($abs), 'document deleted after review');
        $this->assertTrue($v->isVerified($u));
        $this->assertThrows(HttpException::class, fn() => $v->document(TestEnv::user($mod), $id));
    }

    public function testExportAndDeletionLifecycle(): void
    {
        $u = TestEnv::student('del-stu@example.com', ['photo_path' => null]);
        $other = TestEnv::student('del-other@example.com');
        $acc = App::get()->make(AccountService::class);
        $lid = $this->l()->create(TestEnv::user($u), $this->input(), false);
        $export = $acc->export(TestEnv::user($u));
        $this->assertSame('del-stu@example.com', $export['account']['email']);
        $this->assertFalse(isset($export['account']['password_hash']));
        $this->assertSame(1, count($export['listings']));
        $this->assertThrows(ValidationException::class, fn() => $acc->requestDeletion(TestEnv::user($u), 'wrong'));
        $acc->requestDeletion(TestEnv::user($u), 'Test#Passw0rd9');
        $this->assertSame(0, (int)TestEnv::db()->fetchColumn('SELECT discoverable FROM profiles WHERE user_id = ?', [$u]));
        $this->assertSame('closed', TestEnv::db()->fetchColumn('SELECT status FROM listings WHERE id = ?', [$lid]));
        $this->assertSame(0, $acc->processDeletions(), 'grace period');
        TestEnv::db()->run('UPDATE users SET deletion_requested_at = UTC_TIMESTAMP() - INTERVAL 8 DAY WHERE id = ?', [$u]);
        $this->assertSame(1, $acc->processDeletions());
        $row = TestEnv::db()->fetch('SELECT * FROM users WHERE id = ?', [$u]);
        $this->assertSame('deleted', $row['status']);
        $this->assertSame("deleted-$u@deleted.invalid", $row['email']);
        $this->assertSame(null, $row['date_of_birth']);
        $this->assertSame(0, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM listings WHERE user_id = ?', [$u]));
        $this->assertSame('invalid', App::get()->make(\Flatmates\Security\Auth::class)->attempt('del-stu@example.com', 'Test#Passw0rd9', '10.9.9.9', false));
        // The email can be used again for a new account
        TestEnv::makeUser('del-stu@example.com');
        $this->assertTrue($other > 0);
    }

    public function testSsoReceiver(): void
    {
        $secret = str_repeat('x', 48);
        App::get()->settings()->set('sso.secret', $secret);
        $admin = TestEnv::makeUser('sso-admin@example.com', 'super_admin');
        TestEnv::student('sso-student@example.com');
        $sso = App::get()->make(SsoReceiver::class);
        $tok = static fn(array $o = []) => SsoReceiver::sign($o + ['iss' => 'seo.bharatseo.site', 'aud' => '127.0.0.1', 'email' => 'sso-admin@example.com', 'iat' => time(), 'exp' => time() + 60, 'nonce' => bin2hex(random_bytes(16))], $secret);
        $t = $tok();
        $this->assertSame($admin, $sso->consume($t));
        $this->assertSame(null, $sso->consume($t), 'replay rejected');
        $this->assertSame(null, $sso->consume($tok(['exp' => time() - 1])), 'expired');
        $this->assertSame(null, $sso->consume($tok(['aud' => 'evil.example'])), 'audience');
        $this->assertSame(null, $sso->consume($tok(['email' => 'sso-student@example.com'])), 'students cannot SSO');
        $this->assertSame(null, $sso->consume($tok(['email' => 'nobody@example.com'])), 'no auto-create');
        $this->assertSame(null, $sso->consume(SsoReceiver::sign(['aud' => '127.0.0.1', 'email' => 'sso-admin@example.com', 'iat' => time(), 'exp' => time() + 60, 'nonce' => bin2hex(random_bytes(16))], str_repeat('y', 48))), 'bad signature');
    }

    public function testMailQueueRetriesAndCron(): void
    {
        $mailer = App::get()->make(Mailer::class);
        TestEnv::mailbox()->fail = true;
        $id = $mailer->send('q@example.com', 'Hello', 'Body');
        $row = TestEnv::db()->fetch('SELECT * FROM mail_queue WHERE id = ?', [$id]);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int)$row['attempts']);
        TestEnv::mailbox()->fail = false;
        TestEnv::db()->run('UPDATE mail_queue SET send_after = UTC_TIMESTAMP() WHERE id = ?', [$id]);
        $r = App::get()->make(CronTasks::class)->run('mail');
        $this->assertSame('ok', $r['status']);
        $this->assertSame('sent', TestEnv::db()->fetchColumn('SELECT status FROM mail_queue WHERE id = ?', [$id]));
        $this->assertSame('ok', App::get()->make(CronTasks::class)->run('cleanup')['status']);
        $this->assertSame('ok', App::get()->make(CronTasks::class)->run('deletions')['status']);
        $this->assertSame(3, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM cron_runs'));
    }
}
