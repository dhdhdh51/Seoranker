<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\HttpException;
use Flatmates\Core\ValidationException;
use Flatmates\Security\RateLimiter;
use Flatmates\Security\User;

/**
 * Private 1:1 chat between matched students (or a student and a listing owner after an accepted
 * enquiry). Delivered by AJAX polling; only participants can read a conversation.
 */
final class ChatService
{
    public const PAGE = 50;

    public function __construct(private App $app)
    {
    }

    public static function pairKey(string $type, int $a, int $b, ?int $listingId = null): string
    {
        [$x, $y] = [min($a, $b), max($a, $b)];
        return $type === 'match' ? "m:$x:$y" : "l:$listingId:$x:$y";
    }

    public function openConversation(string $type, int $a, int $b, ?int $matchId, ?int $listingId): int
    {
        $key = self::pairKey($type, $a, $b, $listingId);
        $db = $this->app->db();
        $db->run('INSERT IGNORE INTO conversations (type, match_id, listing_id, user_a, user_b, pair_key) VALUES (?, ?, ?, ?, ?, ?)', [$type, $matchId, $listingId, min($a, $b), max($a, $b), $key]);
        $id = (int)$db->fetchColumn('SELECT id FROM conversations WHERE pair_key = ?', [$key]);
        if ($type === 'match' && $matchId) {
            $db->run('UPDATE conversations SET match_id = ? WHERE id = ?', [$matchId, $id]);
        }
        return $id;
    }

    /** Conversation for a participant, with the other person's public info. 404 for everyone else. */
    public function conversationFor(User $u, int $id): array
    {
        $c = $this->app->db()->fetch("SELECT c.*, l.title AS listing_title, l.slug AS listing_slug,
                o.user_id AS other_id, o.display_name AS other_name, o.photo_path AS other_photo, ou.status AS other_status
            FROM conversations c
            JOIN profiles o ON o.user_id = IF(c.user_a = ?, c.user_b, c.user_a)
            JOIN users ou ON ou.id = o.user_id
            LEFT JOIN listings l ON l.id = c.listing_id
            WHERE c.id = ? AND (c.user_a = ? OR c.user_b = ?)", [$u->id, $id, $u->id, $u->id]);
        if (!$c) {
            throw new HttpException(404);
        }
        $c['can_send'] = $this->sendProblem($u, $c) === null;
        $c['send_problem'] = $this->sendProblem($u, $c);
        return $c;
    }

    public function sendProblem(User $u, array $c): ?string
    {
        if (!$u->canInteract()) {
            return 'Your account is restricted, so you cannot send messages.';
        }
        if (!in_array($c['other_status'], ['active', 'restricted'], true)) {
            return 'This account is no longer active.';
        }
        if ($this->app->make(SafetyService::class)->blockedEitherWay($u->id, (int)$c['other_id'])) {
            return 'You can no longer message this person.';
        }
        if ($c['type'] === 'match' && !$this->app->make(MatchService::class)->activeMatch($u->id, (int)$c['other_id'])) {
            return 'This match has ended.';
        }
        if ($this->app->settings()->bool('rules.verified_to_message') && !$this->app->make(VerificationService::class)->isVerified($u->id)) {
            return 'Verify that you are a student to send messages.';
        }
        return null;
    }

    public function send(User $u, int $conversationId, string $body): array
    {
        $c = $this->conversationFor($u, $conversationId);
        if ($p = $this->sendProblem($u, $c)) {
            throw new HttpException(403, $p);
        }
        $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r", '', $body)) ?? '');
        if ($body === '' || mb_strlen($body) > 2000) {
            throw ValidationException::single('body', 'Messages must be 1–2000 characters.');
        }
        $limit = (int)$this->app->settings()->get('rules.messages_per_hour', 60);
        if (!$this->app->make(RateLimiter::class)->hit('msg:' . $u->id, $limit, 3600)) {
            throw new HttpException(429, 'You are sending messages very quickly. Please slow down.');
        }
        $db = $this->app->db();
        $id = $db->insert('messages', ['conversation_id' => $conversationId, 'sender_id' => $u->id, 'body' => $body]);
        $db->run('UPDATE conversations SET last_message_at = UTC_TIMESTAMP() WHERE id = ?', [$conversationId]);
        $link = '/chat/' . $conversationId;
        if (!$db->fetchColumn('SELECT 1 FROM notifications WHERE user_id = ? AND link = ? AND is_read = 0', [(int)$c['other_id'], $link])) {
            $me = $db->fetchColumn('SELECT display_name FROM profiles WHERE user_id = ?', [$u->id]) ?: 'Someone';
            $this->app->make(Notifier::class)->notify((int)$c['other_id'], 'message', "New message from $me", $link);
        }
        return $this->format($db->fetch('SELECT * FROM messages WHERE id = ?', [$id]), $u->id);
    }

    public function format(array $m, int $viewerId): array
    {
        return [
            'id' => (int)$m['id'],
            'mine' => (int)$m['sender_id'] === $viewerId,
            'body' => $m['hidden_at'] ? null : $m['body'],
            'hidden' => $m['hidden_at'] !== null,
            'at' => fmt_dt(substr((string)$m['created_at'], 0, 19), 'd M, H:i'),
            'read' => $m['read_at'] !== null,
        ];
    }

    /** Messages after $sinceId (polling) or the latest page; marks the other person's messages read. */
    public function messages(User $u, int $conversationId, int $sinceId = 0, int $beforeId = 0): array
    {
        $this->conversationFor($u, $conversationId);
        $db = $this->app->db();
        if ($sinceId > 0) {
            $rows = $db->fetchAll('SELECT * FROM messages WHERE conversation_id = ? AND id > ? ORDER BY id LIMIT 200', [$conversationId, $sinceId]);
        } else {
            $rows = array_reverse($db->fetchAll('SELECT * FROM messages WHERE conversation_id = ?' . ($beforeId > 0 ? ' AND id < ?' : '') . ' ORDER BY id DESC LIMIT ' . self::PAGE, $beforeId > 0 ? [$conversationId, $beforeId] : [$conversationId]));
        }
        $db->run('UPDATE messages SET read_at = UTC_TIMESTAMP() WHERE conversation_id = ? AND sender_id <> ? AND read_at IS NULL', [$conversationId, $u->id]);
        $db->run('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND link = ? AND is_read = 0', [$u->id, '/chat/' . $conversationId]);
        return array_map(fn($m) => $this->format($m, $u->id), $rows);
    }

    public function inbox(User $u): array
    {
        return $this->app->db()->fetchAll("SELECT c.id, c.type, c.last_message_at, c.created_at, l.title AS listing_title,
                o.user_id AS other_id, o.display_name AS other_name, o.photo_path AS other_photo,
                (SELECT IF(m.hidden_at IS NULL, m.body, '[removed]') FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
                (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.sender_id <> ? AND m.read_at IS NULL AND m.hidden_at IS NULL) AS unread
            FROM conversations c
            JOIN profiles o ON o.user_id = IF(c.user_a = ?, c.user_b, c.user_a)
            LEFT JOIN listings l ON l.id = c.listing_id
            WHERE (c.user_a = ? OR c.user_b = ?)
              AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = c.user_a AND b.blocked_id = c.user_b) OR (b.blocker_id = c.user_b AND b.blocked_id = c.user_a))
            ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT 200", [$u->id, $u->id, $u->id, $u->id]);
    }

    public function unreadTotal(int $userId): int
    {
        return (int)$this->app->db()->fetchColumn('SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id
            WHERE (c.user_a = ? OR c.user_b = ?) AND m.sender_id <> ? AND m.read_at IS NULL AND m.hidden_at IS NULL', [$userId, $userId, $userId]);
    }
}
