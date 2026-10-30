<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\Database;
use Flatmates\Core\HttpException;
use Flatmates\Core\ValidationException;
use Flatmates\Security\User;

/** Compatibility scoring, the swipe deck, mutual matches and unmatching. */
final class MatchService
{
    public const DECK_SIZE = 20;
    private const POOL = 300;

    public function __construct(private App $app)
    {
    }

    /**
     * Explainable compatibility score (0–100) between two profiles. Only factors where both
     * students answered count, so incomplete profiles are not punished for blanks.
     * Hard conflicts (e.g. smoker vs. "no smoking") cap the score.
     *
     * @return array{score:int, reasons:string[], conflicts:string[]}
     */
    public static function compatibility(array $a, array $b): array
    {
        $earned = 0.0;
        $possible = 0.0;
        $reasons = [];
        $conflicts = [];
        $has = static fn(array $p, string $f): bool => isset($p[$f]) && $p[$f] !== '' && $p[$f] !== null;
        $add = static function (float $weight, float $fraction, ?string $reason = null) use (&$earned, &$possible, &$reasons): void {
            $possible += $weight;
            $earned += $weight * max(0.0, min(1.0, $fraction));
            if ($reason !== null && $fraction >= 0.75) {
                $reasons[] = $reason;
            }
        };

        // Budget overlap (20)
        if ($has($a, 'budget_max') && $has($b, 'budget_max')) {
            $aMin = (int)($a['budget_min'] ?? 0) ?: (int)round($a['budget_max'] * 0.6);
            $bMin = (int)($b['budget_min'] ?? 0) ?: (int)round($b['budget_max'] * 0.6);
            $overlap = min((int)$a['budget_max'], (int)$b['budget_max']) - max($aMin, $bMin);
            if ($overlap >= 0) {
                $add(20, 1, 'Similar budget');
            } else {
                $gap = -$overlap / max(1, min((int)$a['budget_max'], (int)$b['budget_max']));
                $add(20, 1 - $gap * 2);
                if ($gap > 0.3) {
                    $conflicts[] = 'Budgets are quite different';
                }
            }
        }
        // Same college (8) / locality (5)
        if ($has($a, 'college_id') && $has($b, 'college_id')) {
            $add(8, (int)$a['college_id'] === (int)$b['college_id'] ? 1 : 0.3, 'Same college');
        }
        if ($has($a, 'locality') && $has($b, 'locality')) {
            $add(5, mb_strtolower(trim((string)$a['locality'])) === mb_strtolower(trim((string)$b['locality'])) ? 1 : 0.4, 'Looking in the same area');
        }
        // Move-in timing (10)
        if ($has($a, 'move_in_date') && $has($b, 'move_in_date')) {
            $days = abs((int)((strtotime((string)$a['move_in_date']) - strtotime((string)$b['move_in_date'])) / 86400));
            $add(10, $days <= 14 ? 1 : ($days <= 45 ? 0.6 : ($days <= 90 ? 0.3 : 0)), 'Moving in around the same time');
        }
        // Cleanliness (15)
        if ($has($a, 'cleanliness') && $has($b, 'cleanliness')) {
            $d = abs((int)$a['cleanliness'] - (int)$b['cleanliness']);
            $add(15, [1, 0.75, 0.35, 0.1, 0][$d] ?? 0, 'Similar cleanliness standards');
            if ($d >= 3) {
                $conflicts[] = 'Very different cleanliness habits';
            }
        }
        // Sleep schedule (10)
        if ($has($a, 'sleep_schedule') && $has($b, 'sleep_schedule')) {
            $order = ['early' => 0, 'normal' => 1, 'late' => 2];
            $d = abs(($order[$a['sleep_schedule']] ?? 1) - ($order[$b['sleep_schedule']] ?? 1));
            $add(10, [1, 0.55, 0.1][$d], 'Similar sleep schedule');
        }
        // Smoking (10) — respects each person's tolerance
        if ($has($a, 'smoking') && $has($b, 'smoking')) {
            $aBothers = ($b['smoking'] !== 'never') && isset($a['smoking_ok']) && $a['smoking_ok'] !== null && (int)$a['smoking_ok'] === 0;
            $bBothers = ($a['smoking'] !== 'never') && isset($b['smoking_ok']) && $b['smoking_ok'] !== null && (int)$b['smoking_ok'] === 0;
            if ($aBothers || $bBothers) {
                $add(10, 0);
                $conflicts[] = 'Smoking preferences clash';
            } else {
                $add(10, $a['smoking'] === $b['smoking'] ? 1 : 0.7, $a['smoking'] === 'never' && $b['smoking'] === 'never' ? 'Both non-smokers' : null);
            }
        }
        // Drinking (5)
        if ($has($a, 'alcohol') && $has($b, 'alcohol')) {
            $clash = ($b['alcohol'] !== 'never' && isset($a['alcohol_ok']) && $a['alcohol_ok'] !== null && (int)$a['alcohol_ok'] === 0)
                || ($a['alcohol'] !== 'never' && isset($b['alcohol_ok']) && $b['alcohol_ok'] !== null && (int)$b['alcohol_ok'] === 0);
            $add(5, $clash ? 0 : ($a['alcohol'] === $b['alcohol'] ? 1 : 0.6));
            if ($clash) {
                $conflicts[] = 'Different views on drinking at home';
            }
        }
        // Food / kitchen (10)
        if ($has($a, 'diet') || $has($b, 'diet')) {
            $vegOnly = static fn(array $p): bool => ($p['diet_pref'] ?? null) === 'veg_only';
            $nonVeg = static fn(array $p): bool => in_array($p['diet'] ?? null, ['non_veg', 'eggetarian'], true);
            if (($vegOnly($a) && $nonVeg($b)) || ($vegOnly($b) && $nonVeg($a))) {
                $add(10, 0);
                $conflicts[] = 'Kitchen preferences clash (veg-only kitchen)';
            } elseif ($has($a, 'diet') && $has($b, 'diet')) {
                $add(10, $a['diet'] === $b['diet'] ? 1 : 0.7, $a['diet'] === $b['diet'] ? 'Same food habits' : null);
            }
        }
        // Study, social and guests (5 each)
        foreach (['study_habits' => 'Similar study habits', 'social_level' => 'Similar social style', 'guests' => 'Similar comfort with guests'] as $f => $label) {
            if ($has($a, $f) && $has($b, $f)) {
                $add(5, $a[$f] === $b[$f] ? 1 : 0.45, $label);
            }
        }
        // Room type (4) and AC (3)
        if ($has($a, 'room_type') && $has($b, 'room_type')) {
            $add(4, $a['room_type'] === $b['room_type'] || in_array('any', [$a['room_type'], $b['room_type']], true) ? 1 : 0.2);
        }
        if ($has($a, 'ac_pref') && $has($b, 'ac_pref')) {
            $add(3, $a['ac_pref'] === $b['ac_pref'] || in_array('optional', [$a['ac_pref'], $b['ac_pref']], true) ? 1 : 0.3);
        }
        // Complementary situations: one has a room, the other is looking (bonus reason only)
        $statuses = [$a['accommodation_status'] ?? null, $b['accommodation_status'] ?? null];
        if (in_array('looking_room', $statuses, true) && (in_array('has_room', $statuses, true) || in_array('looking_flatmate', $statuses, true))) {
            $reasons[] = 'One of you has a place, the other is looking';
        }

        $score = $possible > 0 ? (int)round($earned / $possible * 100) : 50;
        if ($possible > 0 && $possible < 30) {
            $score = (int)round(($score + 50) / 2); // little data: pull towards neutral
        }
        if ($conflicts) {
            $score = min($score, 85 - 10 * (count($conflicts) - 1));
        }
        return ['score' => max(0, min(100, $score)), 'reasons' => array_slice(array_values(array_unique($reasons)), 0, 4), 'conflicts' => $conflicts];
    }

    /** Ready to use discovery: verified email, onboarding done. */
    public function canDiscover(User $u): ?string
    {
        $p = $this->app->make(ProfileService::class)->get($u->id);
        if (!$u->isStudent()) {
            return 'Discovery is for student accounts.';
        }
        if (!$p || !$p['onboarded_at']) {
            return 'onboarding';
        }
        if (!$p['city_id']) {
            return 'Choose your city in your profile to see flatmates.';
        }
        return null;
    }

    /**
     * Eligible candidates for $viewer, scored and ordered.
     * @param array{college_id?:int, budget_max?:int, verified_only?:bool, move_in_before?:string, room_type?:string, diet?:string, non_smoker?:bool} $filters
     */
    public function deck(User $viewer, array $filters = [], int $limit = self::DECK_SIZE): array
    {
        $me = $this->app->make(ProfileService::class)->get($viewer->id);
        if (!$me || !$me['city_id']) {
            return [];
        }
        $myGender = $me['gender'] ?? null;
        $min = (int)$this->app->settings()->get('rules.min_completeness', 60);
        $where = ["u.role = 'student'", "u.status = 'active'", 'u.email_verified_at IS NOT NULL', 'p.discoverable = 1', 'p.onboarded_at IS NOT NULL', 'p.completeness >= ?', 'p.city_id = ?', 'u.id <> ?'];
        $params = [$min, (int)$me['city_id'], $viewer->id];
        $where[] = 'NOT EXISTS (SELECT 1 FROM swipes s WHERE s.swiper_id = ? AND s.target_id = u.id)';
        $params[] = $viewer->id;
        $where[] = 'NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = ? AND b.blocked_id = u.id) OR (b.blocker_id = u.id AND b.blocked_id = ?))';
        array_push($params, $viewer->id, $viewer->id);
        // Gender preferences are honoured in both directions; a person who did not share a gender never matches "same only".
        if ($me['flatmate_gender_pref'] === 'same') {
            $where[] = 'p.gender = ?';
            $params[] = (string)$myGender;
        }
        $where[] = "(p.flatmate_gender_pref = 'any' OR p.gender = ?)";
        $params[] = (string)$myGender;
        if (!empty($filters['college_id'])) {
            $where[] = 'p.college_id = ?';
            $params[] = (int)$filters['college_id'];
        }
        if (!empty($filters['budget_max'])) {
            $where[] = '(p.budget_min IS NULL OR p.budget_min <= ?)';
            $params[] = (int)$filters['budget_max'];
        }
        if (!empty($filters['move_in_before'])) {
            $where[] = '(p.move_in_date IS NULL OR p.move_in_date <= ?)';
            $params[] = (string)$filters['move_in_before'];
        }
        if (!empty($filters['room_type']) && in_array($filters['room_type'], ['private', 'shared'], true)) {
            $where[] = "(p.room_type IS NULL OR p.room_type IN (?, 'any'))";
            $params[] = $filters['room_type'];
        }
        if (!empty($filters['diet']) && $filters['diet'] === 'veg') {
            $where[] = "p.diet IN ('veg','vegan')";
        }
        if (!empty($filters['non_smoker'])) {
            $where[] = "p.smoking = 'never'";
        }
        if (!empty($filters['verified_only'])) {
            $where[] = "EXISTS (SELECT 1 FROM verifications v WHERE v.user_id = u.id AND v.status = 'approved')";
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['looking_room', 'looking_flatmate', 'has_room'], true)) {
            $where[] = 'p.accommodation_status = ?';
            $params[] = $filters['status'];
        }
        $rows = $this->app->db()->fetchAll('SELECT p.*, u.date_of_birth, co.name AS college_name, c.name AS city_name,
                EXISTS (SELECT 1 FROM verifications v WHERE v.user_id = u.id AND v.status = \'approved\') AS verified,
                EXISTS (SELECT 1 FROM swipes s2 WHERE s2.swiper_id = u.id AND s2.target_id = ? AND s2.action = \'like\') AS liked_me
            FROM users u JOIN profiles p ON p.user_id = u.id LEFT JOIN colleges co ON co.id = p.college_id LEFT JOIN cities c ON c.id = p.city_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY u.last_seen_at DESC LIMIT ' . self::POOL, array_merge([$viewer->id], $params));
        $cards = [];
        foreach ($rows as $r) {
            $c = self::compatibility($me, $r);
            $card = ProfileService::present($r, false);
            $card['score'] = $c['score'];
            $card['reasons'] = $c['reasons'];
            $card['conflicts'] = $c['conflicts'];
            $card['_rank'] = $c['score'] + ((int)$r['verified'] ? 3 : 0) + ((int)$r['liked_me'] ? 2 : 0);
            $cards[] = $card;
        }
        usort($cards, static fn($x, $y) => $y['_rank'] <=> $x['_rank']);
        $cards = array_slice($cards, 0, $limit);
        $badges = $this->app->make(TrustService::class)->forUsers(array_column($cards, 'user_id'));
        return array_map(static function (array $c) use ($badges): array {
            unset($c['_rank']);
            $c['badges'] = $badges[$c['user_id']] ?? [];
            return $c;
        }, $cards);
    }

    public function swipesToday(int $userId): int
    {
        return (int)$this->app->db()->fetchColumn('SELECT COUNT(*) FROM swipes WHERE swiper_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY', [$userId]);
    }

    /** @return array{matched:bool, match_id:?int, conversation_id:?int, remaining:int} */
    public function swipe(User $u, int $targetId, string $action): array
    {
        if (!in_array($action, ['like', 'pass'], true)) {
            throw new HttpException(400);
        }
        if (!$u->canInteract() || !$u->isStudent()) {
            throw new HttpException(403, 'Your account cannot swipe right now.');
        }
        $limit = (int)$this->app->settings()->get('rules.daily_likes', 60);
        $used = $this->swipesToday($u->id);
        if ($used >= $limit) {
            throw ValidationException::single('swipe', "You've reached today's limit of $limit swipes. Come back tomorrow!");
        }
        $db = $this->app->db();
        $target = $db->fetch("SELECT u.id, p.discoverable, p.onboarded_at FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ? AND u.role = 'student' AND u.status = 'active'", [$targetId]);
        if (!$target || $targetId === $u->id || !$target['onboarded_at'] || !(int)$target['discoverable'] || $this->app->make(SafetyService::class)->blockedEitherWay($u->id, $targetId)) {
            throw new HttpException(404, 'This profile is no longer available.');
        }
        return $db->transaction(function (Database $db) use ($u, $targetId, $action, $limit, $used): array {
            $db->run('INSERT IGNORE INTO swipes (swiper_id, target_id, action) VALUES (?, ?, ?)', [$u->id, $targetId, $action]);
            $result = ['matched' => false, 'match_id' => null, 'conversation_id' => null, 'remaining' => max(0, $limit - $used - 1)];
            if ($action !== 'like') {
                return $result;
            }
            $mutual = $db->fetchColumn("SELECT 1 FROM swipes WHERE swiper_id = ? AND target_id = ? AND action = 'like'", [$targetId, $u->id]);
            if (!$mutual) {
                return $result;
            }
            [$a, $b] = [min($u->id, $targetId), max($u->id, $targetId)];
            $existing = $db->fetch('SELECT * FROM matches WHERE user_a = ? AND user_b = ?', [$a, $b]);
            if ($existing && $existing['unmatched_at']) {
                return $result; // once unmatched, they do not re-match automatically
            }
            $ps = $this->app->make(ProfileService::class);
            $score = self::compatibility($ps->get($a) ?? [], $ps->get($b) ?? [])['score'];
            $matchId = $existing ? (int)$existing['id'] : $db->insert('matches', ['user_a' => $a, 'user_b' => $b, 'score' => $score]);
            $convId = $this->app->make(ChatService::class)->openConversation('match', $a, $b, $matchId, null);
            if (!$existing) {
                $n = $this->app->make(Notifier::class);
                $n->notify($targetId, 'match', "It's a match! Say hi 👋", '/chat/' . $convId);
                $n->notify($u->id, 'match', "It's a match! Say hi 👋", '/chat/' . $convId);
                $this->app->make(AuditLog::class)->log('match.created', $u->id, 'match', $matchId);
            }
            return ['matched' => true, 'match_id' => $matchId, 'conversation_id' => $convId] + $result;
        });
    }

    public function activeMatch(int $x, int $y): ?array
    {
        return $this->app->db()->fetch('SELECT * FROM matches WHERE user_a = ? AND user_b = ? AND unmatched_at IS NULL', [min($x, $y), max($x, $y)]);
    }

    public function endMatch(int $x, int $y, int $by): void
    {
        $this->app->db()->run('UPDATE matches SET unmatched_at = UTC_TIMESTAMP(), unmatched_by = ? WHERE user_a = ? AND user_b = ? AND unmatched_at IS NULL', [$by, min($x, $y), max($x, $y)]);
    }

    public function unmatch(User $u, int $matchId): void
    {
        $m = $this->app->db()->fetch('SELECT * FROM matches WHERE id = ? AND (user_a = ? OR user_b = ?)', [$matchId, $u->id, $u->id]);
        if (!$m) {
            throw new HttpException(404);
        }
        $this->endMatch((int)$m['user_a'], (int)$m['user_b'], $u->id);
        $this->app->make(AuditLog::class)->log('match.unmatched', $u->id, 'match', $matchId);
    }

    /** Active matches with the other student's card and conversation. */
    public function matches(User $u): array
    {
        $rows = $this->app->db()->fetchAll("SELECT m.id, m.score, m.created_at, o.user_id AS other_id, o.display_name, o.photo_path, co.name AS college_name, cv.id AS conversation_id, cv.last_message_at,
                (SELECT COUNT(*) FROM messages mm WHERE mm.conversation_id = cv.id AND mm.sender_id <> ? AND mm.read_at IS NULL AND mm.hidden_at IS NULL) AS unread
            FROM matches m
            JOIN profiles o ON o.user_id = IF(m.user_a = ?, m.user_b, m.user_a)
            JOIN users ou ON ou.id = o.user_id AND ou.status IN ('active','restricted')
            LEFT JOIN colleges co ON co.id = o.college_id
            LEFT JOIN conversations cv ON cv.match_id = m.id
            WHERE (m.user_a = ? OR m.user_b = ?) AND m.unmatched_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = m.user_a AND b.blocked_id = m.user_b) OR (b.blocker_id = m.user_b AND b.blocked_id = m.user_a))
            ORDER BY COALESCE(cv.last_message_at, m.created_at) DESC", [$u->id, $u->id, $u->id, $u->id]);
        $badges = $this->app->make(TrustService::class)->forUsers(array_column($rows, 'other_id'));
        foreach ($rows as &$r) {
            $r['badges'] = $badges[(int)$r['other_id']] ?? [];
        }
        return $rows;
    }

    /** Number of people who liked $u and are waiting in their deck (count only — no identities). */
    public function pendingLikes(int $userId): int
    {
        return (int)$this->app->db()->fetchColumn("SELECT COUNT(*) FROM swipes s WHERE s.target_id = ? AND s.action = 'like' AND NOT EXISTS (SELECT 1 FROM swipes r WHERE r.swiper_id = ? AND r.target_id = s.swiper_id)", [$userId, $userId]);
    }
}
