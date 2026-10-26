<?php
declare(strict_types=1);

namespace Flatmates\Tests\Integration;

use Flatmates\Core\App;
use Flatmates\Core\HttpException;
use Flatmates\Core\ValidationException;
use Flatmates\Services\AdminService;
use Flatmates\Services\ChatService;
use Flatmates\Services\MatchService;
use Flatmates\Services\ProfileService;
use Flatmates\Services\SafetyService;
use Flatmates\Tests\Support\TestEnv;
use Flatmates\Tests\TestCase;

final class MatchChatSafetyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestEnv::freshApp();
    }

    private function m(): MatchService
    {
        return App::get()->make(MatchService::class);
    }

    private function deckIds(int $viewer, array $f = []): array
    {
        return array_column($this->m()->deck(TestEnv::user($viewer), $f), 'user_id');
    }

    public function testDeckExclusions(): void
    {
        $me = TestEnv::student('deck-me@example.com');
        $ok = TestEnv::student('deck-ok@example.com');
        $otherCity = TestEnv::student('deck-city@example.com', ['city_id' => 6]);
        $hidden = TestEnv::student('deck-hidden@example.com', ['discoverable' => 0]);
        $incomplete = TestEnv::student('deck-incomplete@example.com', ['completeness' => 20]);
        $unverified = TestEnv::student('deck-unverified@example.com');
        TestEnv::db()->run('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$unverified]);
        $suspended = TestEnv::student('deck-susp@example.com');
        TestEnv::db()->run("UPDATE users SET status = 'suspended' WHERE id = ?", [$suspended]);
        $staff = TestEnv::makeUser('deck-staff@example.com', 'moderator');
        $ids = $this->deckIds($me);
        $this->assertTrue(in_array($ok, $ids, true));
        foreach ([$me, $otherCity, $hidden, $incomplete, $unverified, $suspended, $staff] as $x) {
            $this->assertFalse(in_array($x, $ids, true), "user $x must not be in deck");
        }
        // privacy: deck cards never contain email / dob / hidden gender
        $card = $this->m()->deck(TestEnv::user($me))[0];
        $this->assertFalse(isset($card['email']) || isset($card['date_of_birth']) || isset($card['gender']));
    }

    public function testGenderPreferenceRespectedBothWays(): void
    {
        $woman = TestEnv::student('g-woman@example.com', ['city_id' => 3, 'gender' => 'female', 'flatmate_gender_pref' => 'same']);
        $man = TestEnv::student('g-man@example.com', ['city_id' => 3, 'gender' => 'male']);
        $woman2 = TestEnv::student('g-woman2@example.com', ['city_id' => 3, 'gender' => 'female']);
        $noGender = TestEnv::student('g-none@example.com', ['city_id' => 3, 'gender' => null]);
        $this->assertSame([$woman2], $this->deckIds($woman));
        $this->assertFalse(in_array($woman, $this->deckIds($man), true), 'same-only user hidden from other genders');
        $this->assertTrue(in_array($woman, $this->deckIds($woman2), true));
        $this->assertFalse(in_array($woman, $this->deckIds($noGender), true));
    }

    public function testFiltersApply(): void
    {
        $me = TestEnv::student('f-me@example.com', ['city_id' => 7]);
        $smoker = TestEnv::student('f-smoker@example.com', ['city_id' => 7, 'smoking' => 'yes']);
        $rich = TestEnv::student('f-rich@example.com', ['city_id' => 7, 'budget_min' => 30000, 'budget_max' => 40000]);
        $plain = TestEnv::student('f-plain@example.com', ['city_id' => 7]);
        $this->assertFalse(in_array($smoker, $this->deckIds($me, ['non_smoker' => true]), true));
        $this->assertFalse(in_array($rich, $this->deckIds($me, ['budget_max' => 12000]), true));
        $this->assertTrue(in_array($plain, $this->deckIds($me, ['budget_max' => 12000, 'non_smoker' => true]), true));
        $this->assertSame([], $this->deckIds($me, ['verified_only' => true]));
        TestEnv::db()->insert('verifications', ['user_id' => $plain, 'method' => 'college_email', 'status' => 'approved']);
        $this->assertSame([$plain], $this->deckIds($me, ['verified_only' => true]));
    }

    public function testMutualLikeCreatesMatchAndConversationOnce(): void
    {
        $a = TestEnv::student('mm-a@example.com', ['city_id' => 9]);
        $b = TestEnv::student('mm-b@example.com', ['city_id' => 9]);
        $r1 = $this->m()->swipe(TestEnv::user($a), $b, 'like');
        $this->assertFalse($r1['matched']);
        $this->assertFalse(in_array($b, $this->deckIds($a), true), 'swiped profile leaves deck');
        $r2 = $this->m()->swipe(TestEnv::user($b), $a, 'like');
        $this->assertTrue($r2['matched']);
        $this->assertSame(1, (int)TestEnv::db()->fetchColumn('SELECT COUNT(*) FROM matches WHERE user_a = ? AND user_b = ?', [min($a, $b), max($a, $b)]));
        $this->assertSame(1, (int)TestEnv::db()->fetchColumn("SELECT COUNT(*) FROM conversations WHERE type = 'match' AND pair_key = ?", [ChatService::pairKey('match', $a, $b)]));
        $this->assertSame(2, (int)TestEnv::db()->fetchColumn("SELECT COUNT(*) FROM notifications WHERE type = 'match' AND user_id IN (?, ?)", [$a, $b]));
        // Chat
        $chat = App::get()->make(ChatService::class);
        $conv = (int)$r2['conversation_id'];
        $msg = $chat->send(TestEnv::user($a), $conv, "  Hi! <b>bold</b>\x07  ");
        $this->assertSame('Hi! <b>bold</b>', $chat->messages(TestEnv::user($b), $conv)[0]['body'], 'stored as text, escaped on output');
        $this->assertSame(1, count($chat->messages(TestEnv::user($a), $conv, 0)));
        $this->assertSame([], $chat->messages(TestEnv::user($a), $conv, $msg['id']));
        $outsider = TestEnv::student('mm-out@example.com', ['city_id' => 9]);
        $e = $this->assertThrows(HttpException::class, fn() => $chat->messages(TestEnv::user($outsider), $conv));
        $this->assertSame(404, $e->status);
        $this->assertThrows(HttpException::class, fn() => $chat->send(TestEnv::user($outsider), $conv, 'hack'));
        $this->assertThrows(ValidationException::class, fn() => $chat->send(TestEnv::user($a), $conv, '   '));
        // Restricted users cannot send
        TestEnv::db()->run("UPDATE users SET status = 'restricted' WHERE id = ?", [$a]);
        $this->assertThrows(HttpException::class, fn() => $chat->send(TestEnv::user($a), $conv, 'hello'));
        TestEnv::db()->run("UPDATE users SET status = 'active' WHERE id = ?", [$a]);
        // Unmatch closes chat; no automatic re-match
        $match = $this->m()->activeMatch($a, $b);
        $this->m()->unmatch(TestEnv::user($b), (int)$match['id']);
        $this->assertThrows(HttpException::class, fn() => $chat->send(TestEnv::user($a), $conv, 'still there?'));
        $this->assertSame(0, count($this->m()->matches(TestEnv::user($a))));
    }

    public function testDailySwipeLimit(): void
    {
        App::get()->settings()->set('rules.daily_likes', 5);
        $me = TestEnv::student('lim-me@example.com', ['city_id' => 10]);
        for ($i = 0; $i < 6; $i++) {
            $ids[] = TestEnv::student("lim-$i@example.com", ['city_id' => 10]);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->m()->swipe(TestEnv::user($me), $ids[$i], 'pass');
        }
        $this->assertThrows(ValidationException::class, fn() => $this->m()->swipe(TestEnv::user($me), $ids[5], 'like'));
        App::get()->settings()->set('rules.daily_likes', 60);
    }

    public function testBlockingHidesEverywhereAndEndsMatch(): void
    {
        $a = TestEnv::student('blk-a@example.com', ['city_id' => 11]);
        $b = TestEnv::student('blk-b@example.com', ['city_id' => 11]);
        $this->m()->swipe(TestEnv::user($a), $b, 'like');
        $r = $this->m()->swipe(TestEnv::user($b), $a, 'like');
        App::get()->make(SafetyService::class)->block(TestEnv::user($a), $b);
        $this->assertSame(null, $this->m()->activeMatch($a, $b));
        $this->assertSame(null, App::get()->make(ProfileService::class)->viewFor(TestEnv::user($b), $a));
        $this->assertSame(null, App::get()->make(ProfileService::class)->viewFor(TestEnv::user($a), $b));
        $this->assertSame([], App::get()->make(ChatService::class)->inbox(TestEnv::user($b)));
        $this->assertThrows(HttpException::class, fn() => App::get()->make(ChatService::class)->send(TestEnv::user($b), (int)$r['conversation_id'], 'hey'));
        $c = TestEnv::student('blk-c@example.com', ['city_id' => 11]);
        App::get()->make(SafetyService::class)->block(TestEnv::user($c), $a);
        $this->assertFalse(in_array($c, $this->deckIds($a), true), 'blocked-by hides too');
        $this->assertThrows(HttpException::class, fn() => $this->m()->swipe(TestEnv::user($a), $c, 'like'));
    }

    public function testProfileVisibilityMatchesOnly(): void
    {
        $a = TestEnv::student('vis-a@example.com', ['city_id' => 12, 'visibility' => 'matches']);
        $b = TestEnv::student('vis-b@example.com', ['city_id' => 12]);
        $ps = App::get()->make(ProfileService::class);
        $this->assertSame(null, $ps->viewFor(TestEnv::user($b), $a));
        $this->m()->swipe(TestEnv::user($a), $b, 'like');
        $this->m()->swipe(TestEnv::user($b), $a, 'like');
        $this->assertSame('Vis-a', $ps->viewFor(TestEnv::user($b), $a)['display_name']);
    }

    public function testReportsEscalateAndModerationRequiresPermission(): void
    {
        $bad = TestEnv::student('rep-bad@example.com');
        $r1 = TestEnv::student('rep-1@example.com');
        $r2 = TestEnv::student('rep-2@example.com');
        $s = App::get()->make(SafetyService::class);
        $id1 = $s->report(TestEnv::user($r1), 'user', $bad, 'harassment', 'threatening messages');
        $this->assertThrows(ValidationException::class, fn() => $s->report(TestEnv::user($r1), 'user', $bad, 'spam'), 'once per target');
        $this->assertThrows(ValidationException::class, fn() => $s->report(TestEnv::user($bad), 'user', $bad, 'spam'), 'not yourself');
        $this->assertThrows(ValidationException::class, fn() => $s->report(TestEnv::user($r1), 'user', $r2, 'bogus'));
        $s->report(TestEnv::user($r2), 'user', $bad, 'scam');
        $this->assertSame('escalated', TestEnv::db()->fetchColumn('SELECT status FROM reports WHERE id = ?', [$id1]));
        $modReportsOnly = TestEnv::makeUser('rep-mod@example.com', 'moderator', ['permissions' => 'reports']);
        $this->assertThrows(HttpException::class, fn() => $s->decide(TestEnv::user($modReportsOnly), $id1, 'suspend'), 'needs users permission');
        $noPerm = TestEnv::makeUser('rep-noperm@example.com', 'moderator', ['permissions' => 'listings']);
        $this->assertThrows(HttpException::class, fn() => $s->decide(TestEnv::user($noPerm), $id1, 'dismiss'));
        $admin = TestEnv::makeUser('rep-admin@example.com', 'super_admin');
        $s->decide(TestEnv::user($admin), $id1, 'suspend', 'Harassment confirmed');
        $this->assertSame('suspended', TestEnv::db()->fetchColumn('SELECT status FROM users WHERE id = ?', [$bad]));
        $this->assertSame(0, (int)TestEnv::db()->fetchColumn("SELECT COUNT(*) FROM reports WHERE target_user_id = ? AND status IN ('open','escalated')", [$bad]));
        $this->assertTrue((bool)TestEnv::db()->fetchColumn("SELECT 1 FROM audit_logs WHERE action = 'report.suspend'"));
    }

    public function testMessageReportOnlyByParticipantAndHide(): void
    {
        $a = TestEnv::student('mr-a@example.com', ['city_id' => 4]);
        $b = TestEnv::student('mr-b@example.com', ['city_id' => 4]);
        $x = TestEnv::student('mr-x@example.com', ['city_id' => 4]);
        $this->m()->swipe(TestEnv::user($a), $b, 'like');
        $r = $this->m()->swipe(TestEnv::user($b), $a, 'like');
        $msg = App::get()->make(ChatService::class)->send(TestEnv::user($a), (int)$r['conversation_id'], 'send me money');
        $s = App::get()->make(SafetyService::class);
        $this->assertThrows(HttpException::class, fn() => $s->report(TestEnv::user($x), 'message', $msg['id'], 'scam'), 'outsiders cannot report private messages');
        $rid = $s->report(TestEnv::user($b), 'message', $msg['id'], 'scam');
        $ev = $s->evidence($s->find($rid));
        $this->assertSame($msg['id'], $ev['focus']);
        $s->decide(TestEnv::user(TestEnv::makeUser('mr-admin@example.com', 'super_admin')), $rid, 'hide_content');
        $m = App::get()->make(ChatService::class)->messages(TestEnv::user($b), (int)$r['conversation_id']);
        $this->assertSame(null, $m[0]['body']);
        $this->assertTrue($m[0]['hidden']);
    }

    public function testAdminStatusRules(): void
    {
        $admin = TestEnv::makeUser('as-admin@example.com', 'super_admin');
        $mod = TestEnv::makeUser('as-mod@example.com', 'moderator', ['permissions' => 'users']);
        $stu = TestEnv::student('as-stu@example.com');
        $svc = App::get()->make(AdminService::class);
        $this->assertThrows(ValidationException::class, fn() => $svc->setStatus(TestEnv::user($mod), $stu, 'restricted', ''), 'reason required');
        $svc->setStatus(TestEnv::user($mod), $stu, 'restricted', 'Spam');
        $this->assertFalse(TestEnv::user($stu)->canInteract());
        $this->assertThrows(HttpException::class, fn() => $svc->setStatus(TestEnv::user($mod), $admin, 'suspended', 'x'), 'moderator cannot touch staff');
        $this->assertThrows(HttpException::class, fn() => $svc->setRole(TestEnv::user($mod), $stu, 'super_admin', []));
        $this->assertThrows(HttpException::class, fn() => $svc->setRole(TestEnv::user($admin), $admin, 'student', []), 'not own role');
        $svc->setRole(TestEnv::user($admin), $stu, 'moderator', ['reports', 'evil']);
        $this->assertSame('reports', TestEnv::db()->fetchColumn('SELECT permissions FROM users WHERE id = ?', [$stu]));
    }
}
