<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Integration;

use BharatSeo\Core\HttpException;
use BharatSeo\Core\ValidationException;
use BharatSeo\Services\ApprovalService;
use BharatSeo\Services\Mail\MailException;
use BharatSeo\Services\Mail\SmtpTransport;
use BharatSeo\Services\Mail\MailMessage;
use BharatSeo\Services\MailerService;
use BharatSeo\Services\OutreachSender;
use BharatSeo\Services\OutreachService;
use BharatSeo\Services\ProspectService;
use BharatSeo\Services\SuppressionService;
use BharatSeo\Tests\Support\FakeTransport;
use BharatSeo\Tests\Support\TestEnv;
use BharatSeo\Tests\TestCase;

final class OutreachFlowTest extends TestCase
{
    private static array $ids;
    private static int $campaign;
    private static FakeTransport $mail;

    public static function setUpBeforeClass(): void
    {
        $app = TestEnv::freshApp();
        self::$ids = TestEnv::seed();
        $app->settings()->set('smtp.from_email', 'outreach@agency.test');
        $app->settings()->set('smtp.host', 'smtp.agency.test');
        self::$mail = new FakeTransport();
        $app->make(MailerService::class)->setTransport(self::$mail);
        self::$campaign = $app->make(OutreachService::class)->saveCampaign(TestEnv::user(self::$ids['manager']), null, ['project_id' => self::$ids['projectA'], 'name' => 'Resource outreach', 'type' => 'resource_page', 'status' => 'active', 'follow_up_days' => 7, 'max_follow_ups' => 1, 'auto_drafts' => '1']);
    }

    public function setUp(): void
    {
        self::$mail->sent = [];
        self::$mail->failWith = null;
        TestEnv::app()->settings()->set('outreach.daily_limit', 30);
        TestEnv::app()->settings()->set('outreach.per_project_daily_limit', 15);
        TestEnv::app()->settings()->set('queue.paused', false);
    }

    private function approvedProspect(string $url, ?string $email): int
    {
        $app = TestEnv::app();
        $id = (int)$app->make(ProspectService::class)->createFromUrl(TestEnv::project(self::$ids['projectA']), $url, ['category' => 'resource_page', 'contact_email' => $email, 'title' => 'Soil resources'], null, null)['id'];
        $app->make(ProspectService::class)->transition($id, 'needs_review', null);
        $app->make(ApprovalService::class)->decideProspect(TestEnv::user(self::$ids['manager']), $id, true, null);
        return $id;
    }

    private function approvedMessage(string $url, string $email): int
    {
        $svc = TestEnv::app()->make(OutreachService::class);
        $mgr = TestEnv::user(self::$ids['manager']);
        $pid = $this->approvedProspect($url, $email);
        $mid = $svc->createDraft($mgr, self::$campaign, $pid);
        $svc->submitForApproval($mgr, $mid);
        $req = (int)TestEnv::db()->fetchColumn("SELECT id FROM approval_requests WHERE entity_type = 'outreach_message' AND entity_id = ? AND status = 'pending'", [$mid]);
        TestEnv::app()->make(ApprovalService::class)->decideMessage($mgr, $req, true, null);
        return $mid;
    }

    public function testDraftCreationIsIdempotentAndRequiresApprovedProspect(): void
    {
        $svc = TestEnv::app()->make(OutreachService::class);
        $mgr = TestEnv::user(self::$ids['manager']);
        $pid = $this->approvedProspect('https://soilblog.example/links/', 'editor@soilblog.example');
        $m1 = $svc->createDraft($mgr, self::$campaign, $pid);
        $m2 = $svc->createDraft($mgr, self::$campaign, $pid);
        $this->assertSame($m1, $m2, 'Duplicate draft prevented');
        $m = TestEnv::db()->fetch('SELECT * FROM outreach_messages WHERE id = ?', [$m1]);
        $this->assertSame('draft', $m['status']);
        $this->assertSame('editor@soilblog.example', $m['recipient_email']);
        $this->assertSame(1, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM outreach_message_revisions WHERE message_id = ?', [$m1]));
        $unapproved = (int)TestEnv::app()->make(ProspectService::class)->createFromUrl(TestEnv::project(self::$ids['projectA']), 'https://new.example/', [], null, null)['id'];
        $this->assertThrows(ValidationException::class, fn() => $svc->createDraft($mgr, self::$campaign, $unapproved));
        $this->assertThrows(HttpException::class, fn() => $svc->createDraft(TestEnv::user(self::$ids['clientA_user']), self::$campaign, $pid), 'Clients cannot create drafts');
    }

    public function testEditsAreGuardedVersionedAndInvalidateApproval(): void
    {
        $svc = TestEnv::app()->make(OutreachService::class);
        $mgr = TestEnv::user(self::$ids['manager']);
        $mid = $this->approvedMessage('https://editme.example/', 'team@editme.example');
        $this->assertSame('approved', TestEnv::db()->fetchColumn('SELECT status FROM outreach_messages WHERE id = ?', [$mid]));
        $this->assertThrows(ValidationException::class, fn() => $svc->updateDraft($mgr, $mid, ['subject' => 'Hi', 'body' => "Hi Rahul,\n\nAs we discussed, please add the link. Thanks so much for this.", 'recipient_email' => 'team@editme.example']), 'Fabricated name/relationship blocked');
        $svc->updateDraft($mgr, $mid, ['subject' => 'Updated subject', 'body' => "Hello editme team,\n\nA short, honest note about a resource that may help your readers.\n\nThanks", 'recipient_email' => 'team@editme.example', 'recipient_name' => '']);
        $m = TestEnv::db()->fetch('SELECT * FROM outreach_messages WHERE id = ?', [$mid]);
        $this->assertSame('draft', $m['status'], 'Editing an approved message returns it to draft');
        $this->assertSame(null, $m['approved_revision']);
        $this->assertSame(2, (int)$m['revision']);
        $this->assertSame(2, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM outreach_message_revisions WHERE message_id = ?', [$mid]));
        TestEnv::app()->make(OutreachSender::class)->run();
        $this->assertSame(0, count(self::$mail->sent), 'Unapproved revision is never sent');
    }

    public function testManagerAndClientPolicyRequiresBothApprovals(): void
    {
        TestEnv::db()->update('projects', ['approval_policy' => 'manager_and_client'], 'id = ?', [self::$ids['projectA']]);
        $svc = TestEnv::app()->make(OutreachService::class);
        $approvals = TestEnv::app()->make(ApprovalService::class);
        $mgr = TestEnv::user(self::$ids['manager']);
        $pid = $this->approvedProspect('https://dual.example/', 'hello@dual.example');
        $mid = $svc->createDraft($mgr, self::$campaign, $pid);
        $svc->submitForApproval($mgr, $mid);
        $reqs = TestEnv::db()->fetchPairs("SELECT required_party, id FROM approval_requests WHERE entity_id = ? AND entity_type = 'outreach_message' AND status = 'pending'", [$mid]);
        $this->assertSame(2, count($reqs), 'One pending request per required party');
        $this->assertTrue(isset($reqs['client'], $reqs['manager']));
        $this->assertThrows(HttpException::class, fn() => $approvals->decideMessage($mgr, (int)$reqs['client'], true, null), 'Manager cannot approve as client');
        $this->assertThrows(HttpException::class, fn() => $approvals->decideMessage(TestEnv::user(self::$ids['clientB_user']), (int)$reqs['client'], true, null), 'Other client cannot even see it');
        $this->assertSame('partially_approved', $approvals->decideMessage($mgr, (int)$reqs['manager'], true, null));
        $this->assertSame('needs_review', TestEnv::db()->fetchColumn('SELECT status FROM outreach_messages WHERE id = ?', [$mid]));
        $this->assertSame('approved', $approvals->decideMessage(TestEnv::user(self::$ids['clientA_user']), (int)$reqs['client'], true, 'Looks good'));
        $this->assertSame('approved', TestEnv::db()->fetchColumn('SELECT status FROM outreach_messages WHERE id = ?', [$mid]));
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM audit_logs WHERE action = 'approval.approved' AND entity_id = ?", [$mid]));
        TestEnv::db()->update('projects', ['approval_policy' => 'manager'], 'id = ?', [self::$ids['projectA']]);
        TestEnv::db()->run("UPDATE outreach_messages SET status = 'cancelled' WHERE id = ?", [$mid]);
    }

    public function testRejectionReturnsToDraft(): void
    {
        $svc = TestEnv::app()->make(OutreachService::class);
        $mgr = TestEnv::user(self::$ids['manager']);
        $pid = $this->approvedProspect('https://rejectme.example/', 'x@rejectme.example');
        $mid = $svc->createDraft($mgr, self::$campaign, $pid);
        $svc->submitForApproval($mgr, $mid);
        $req = (int)TestEnv::db()->fetchColumn("SELECT id FROM approval_requests WHERE entity_id = ? AND entity_type='outreach_message' AND status='pending'", [$mid]);
        $this->assertThrows(ValidationException::class, fn() => TestEnv::app()->make(ApprovalService::class)->decideMessage($mgr, $req, false, ''));
        TestEnv::app()->make(ApprovalService::class)->decideMessage($mgr, $req, false, 'Tone too salesy');
        $m = TestEnv::db()->fetch('SELECT status, last_error FROM outreach_messages WHERE id = ?', [$mid]);
        $this->assertSame('draft', $m['status']);
        $this->assertContains('Tone too salesy', (string)$m['last_error']);
        TestEnv::db()->run("UPDATE outreach_messages SET status = 'cancelled' WHERE id = ?", [$mid]);
    }

    public function testSenderSendsApprovedWithUnsubscribeAndProtections(): void
    {
        TestEnv::db()->run("UPDATE outreach_messages SET status = 'cancelled' WHERE status IN ('approved','draft','needs_review')");
        $mid = $this->approvedMessage('https://sendme.example/resources/', 'editor@sendme.example');
        $stats = TestEnv::app()->make(OutreachSender::class)->run();
        $this->assertSame(1, $stats['sent']);
        $this->assertSame(1, count(self::$mail->sent));
        $msg = self::$mail->sent[0];
        $this->assertSame('editor@sendme.example', $msg->toEmail);
        $this->assertContains('/unsubscribe/', $msg->body);
        $mime = $msg->toMime('<x@y>');
        $this->assertContains('List-Unsubscribe:', $mime);
        $this->assertContains('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $mime);
        $this->assertContains('Reply-To: hello@greenfarm.in', $mime);
        $m = TestEnv::db()->fetch('SELECT * FROM outreach_messages WHERE id = ?', [$mid]);
        $this->assertSame('sent', $m['status']);
        $this->assertSame('outreach_sent', TestEnv::db()->fetchColumn('SELECT status FROM prospect_pages WHERE id = ?', [$m['prospect_page_id']]));
        // Running again never re-sends.
        TestEnv::app()->make(OutreachSender::class)->run();
        $this->assertSame(1, count(self::$mail->sent), 'Duplicate-send protection');
        // Per-domain cooldown: a different contact on the same domain is deferred, not sent.
        $mid2 = $this->approvedMessage('https://sendme.example/other/', 'partnerships@sendme.example');
        $r = TestEnv::app()->make(OutreachSender::class)->run();
        $this->assertSame(1, $r['deferred']);
        $m2 = TestEnv::db()->fetch('SELECT status, next_attempt_at, last_error FROM outreach_messages WHERE id = ?', [$mid2]);
        $this->assertSame('approved', $m2['status']);
        $this->assertContains('cooldown', (string)$m2['last_error']);
        $this->assertTrue($m2['next_attempt_at'] !== null);
        $this->assertSame(1, count(self::$mail->sent));
        // Duplicate recipient in the same project (another page) is blocked outright.
        $mid3 = $this->approvedMessage('https://sendme.example/third/', 'editor@sendme.example');
        TestEnv::app()->make(OutreachSender::class)->run();
        $m3 = TestEnv::db()->fetch('SELECT status, last_error FROM outreach_messages WHERE id = ?', [$mid3]);
        $this->assertSame('cancelled', $m3['status']);
        $this->assertContains('Duplicate', (string)$m3['last_error']);
        $this->assertSame(1, count(self::$mail->sent));
        // Unsubscribe link → global suppression → queued mail to that address is suppressed.
        TestEnv::db()->run('UPDATE outreach_messages SET recipient_email = ? WHERE id = ?', ['editor@sendme.example', $mid2]);
        $email = TestEnv::app()->make(SuppressionService::class)->unsubscribeByToken($m['unsubscribe_token']);
        $this->assertSame('editor@sendme.example', $email);
        $this->assertSame('suppressed', TestEnv::db()->fetchColumn('SELECT status FROM outreach_messages WHERE id = ?', [$mid2]));
        $this->assertTrue(TestEnv::app()->make(SuppressionService::class)->isSuppressed('EDITOR@sendme.example', self::$ids['projectB']), 'Unsubscribe applies to every project');
        $this->assertThrows(ValidationException::class, fn() => TestEnv::app()->make(SuppressionService::class)->remove((int)TestEnv::db()->fetchColumn("SELECT id FROM email_suppressions WHERE reason='unsubscribe'"), 1), 'Unsubscribes cannot be removed');
        $this->assertThrows(ValidationException::class, fn() => TestEnv::app()->make(OutreachService::class)->submitForApproval(TestEnv::user(self::$ids['manager']), $mid3), 'Suppressed/cancelled recipients cannot be resubmitted');
    }

    public function testDailyLimitPauseAndSmtpNotConfigured(): void
    {
        TestEnv::db()->run("UPDATE outreach_messages SET status = 'cancelled' WHERE status IN ('approved','draft','needs_review')");
        TestEnv::app()->settings()->set('outreach.daily_limit', 1);
        $sentToday = TestEnv::app()->make(OutreachSender::class)->sentToday();
        $this->assertGreaterOrEqual(1, $sentToday);
        $this->approvedMessage('https://limit.example/', 'a@limit.example');
        $r = TestEnv::app()->make(OutreachSender::class)->run();
        $this->assertSame('daily_limit_reached', $r['status']);
        $this->assertSame(0, $r['sent']);
        TestEnv::app()->settings()->set('outreach.daily_limit', 30);
        TestEnv::app()->settings()->set('queue.paused', true);
        $this->assertSame('paused', TestEnv::app()->make(OutreachSender::class)->run()['status']);
        TestEnv::app()->settings()->set('queue.paused', false);
        TestEnv::app()->make(MailerService::class)->setTransport(null);
        TestEnv::app()->settings()->set('smtp.host', '');
        $this->assertSame('smtp_not_configured', TestEnv::app()->make(OutreachSender::class)->run()['status']);
        TestEnv::app()->settings()->set('smtp.host', 'smtp.agency.test');
        TestEnv::app()->make(MailerService::class)->setTransport(self::$mail);
        $r = TestEnv::app()->make(OutreachSender::class)->run();
        $this->assertSame(1, $r['sent']);
    }

    public function testSmtpFailureIsRetriedThenFailedWithoutCrashing(): void
    {
        TestEnv::db()->run("UPDATE outreach_messages SET status = 'cancelled' WHERE status IN ('approved','draft','needs_review')");
        $mid = $this->approvedMessage('https://smtpfail.example/', 'ops@smtpfail.example');
        // Real SMTP transport against a closed local port → connection refused.
        $real = new SmtpTransport('127.0.0.1', 1, 'tls', 'user', 'secret', 2);
        TestEnv::app()->make(MailerService::class)->setTransport($real);
        TestEnv::app()->settings()->set('outreach.max_send_attempts', 2);
        $r = TestEnv::app()->make(OutreachSender::class)->run();
        $this->assertSame(1, $r['deferred']);
        $m = TestEnv::db()->fetch('SELECT * FROM outreach_messages WHERE id = ?', [$mid]);
        $this->assertSame('approved', $m['status'], 'Temporary SMTP failure keeps the message approved for retry');
        $this->assertContains('Could not connect', (string)$m['last_error']);
        $this->assertNotContains('secret', (string)$m['last_error']);
        $this->assertTrue($m['next_attempt_at'] !== null);
        TestEnv::db()->run('UPDATE outreach_messages SET next_attempt_at = NULL WHERE id = ?', [$mid]);
        TestEnv::app()->make(OutreachSender::class)->run();
        $this->assertSame('failed', TestEnv::db()->fetchColumn('SELECT status FROM outreach_messages WHERE id = ?', [$mid]), 'After max attempts the message fails and notifies');
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM notifications WHERE type = 'outreach_failed'"));
        // Permanent 5xx rejection fails immediately.
        $mid2 = $this->approvedMessage('https://reject550.example/', 'nobody@reject550.example');
        $fake = new FakeTransport();
        $fake->failWith = new MailException('SMTP error: 550 mailbox unavailable', 550);
        TestEnv::app()->make(MailerService::class)->setTransport($fake);
        TestEnv::app()->make(OutreachSender::class)->run();
        $this->assertSame('failed', TestEnv::db()->fetchColumn('SELECT status FROM outreach_messages WHERE id = ?', [$mid2]));
        TestEnv::app()->make(MailerService::class)->setTransport(self::$mail);
        $this->assertThrows(MailException::class, fn() => (new SmtpTransport('127.0.0.1', 1, 'none', '', '', 1))->send(new MailMessage('a@b.in', 'A', 'c@d.in', null, 'S', 'B')));
    }

    public function testInterruptedSendIsNeverAutoResent(): void
    {
        $mid = $this->approvedMessage('https://interrupted.example/', 'x@interrupted.example');
        TestEnv::db()->run("UPDATE outreach_messages SET status = 'sending', updated_at = UTC_TIMESTAMP() - INTERVAL 20 MINUTE WHERE id = ?", [$mid]);
        $this->assertSame(1, TestEnv::app()->make(OutreachSender::class)->failStaleSending());
        $this->assertSame('failed', TestEnv::db()->fetchColumn('SELECT status FROM outreach_messages WHERE id = ?', [$mid]));
    }

    public function testFollowUpDraftsAfterDelayAndStopOnReply(): void
    {
        $svc = TestEnv::app()->make(OutreachService::class);
        $mgr = TestEnv::user(self::$ids['manager']);
        TestEnv::db()->run("UPDATE outreach_messages SET status = 'cancelled' WHERE status IN ('approved','draft','needs_review')");
        $mid = $this->approvedMessage('https://followup.example/', 'ed@followup.example');
        TestEnv::app()->make(OutreachSender::class)->run();
        $this->assertSame('sent', TestEnv::db()->fetchColumn('SELECT status FROM outreach_messages WHERE id = ?', [$mid]));
        $this->assertSame(0, $svc->generateDueFollowUps(), 'Not due yet');
        TestEnv::db()->run('UPDATE outreach_messages SET sent_at = UTC_TIMESTAMP() - INTERVAL 8 DAY WHERE id = ?', [$mid]);
        $this->assertSame(1, $svc->generateDueFollowUps());
        $f = TestEnv::db()->fetch('SELECT * FROM outreach_messages WHERE parent_message_id = ?', [$mid]);
        $this->assertSame('draft', $f['status'], 'Follow-ups are drafts that still need approval');
        $this->assertSame(1, (int)$f['follow_up_number']);
        $this->assertSame(0, $svc->generateDueFollowUps(), 'Idempotent');
        $svc->setReplyStatus($mgr, $mid, 'negative');
        $this->assertSame('cancelled', TestEnv::db()->fetchColumn('SELECT status FROM outreach_messages WHERE id = ?', [$f['id']]), 'A "no" cancels pending follow-ups');
    }
}
