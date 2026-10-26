<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\Database;
use Flatmates\Core\HttpException;
use Flatmates\Core\ValidationException;
use Flatmates\Security\User;

/** Listing enquiries and viewing requests. Accepting one opens a private conversation. */
final class EnquiryService
{
    public function __construct(private App $app)
    {
    }

    public function create(User $u, int $listingId, string $kind, string $message, ?string $viewingAt): int
    {
        if (!$u->isStudent() || !$u->canInteract()) {
            throw new HttpException(403, 'Your account cannot send enquiries right now.');
        }
        $db = $this->app->db();
        $l = $db->fetch("SELECT l.* FROM listings l JOIN users u ON u.id = l.user_id WHERE l.id = ? AND l.status = 'published' AND u.status = 'active'", [$listingId]);
        if (!$l || $this->app->make(SafetyService::class)->blockedEitherWay($u->id, (int)$l['user_id'])) {
            throw new HttpException(404);
        }
        if ((int)$l['user_id'] === $u->id) {
            throw ValidationException::single('message', 'This is your own listing.');
        }
        $kind = $kind === 'viewing' ? 'viewing' : 'enquiry';
        $message = trim(str_replace("\r", '', $message));
        if (mb_strlen($message) < 10 || mb_strlen($message) > 1000) {
            throw ValidationException::single('message', 'Write a short message (10–1000 characters) introducing yourself.');
        }
        $when = null;
        if ($kind === 'viewing') {
            $t = $viewingAt ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $viewingAt, $this->app->timezone()) : false;
            if (!$t || $t < new \DateTimeImmutable('now', $this->app->timezone()) || $t > new \DateTimeImmutable('+90 days')) {
                throw ValidationException::single('viewing_at', 'Choose a viewing time in the next 90 days.');
            }
            $when = $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if ($db->fetchColumn("SELECT 1 FROM enquiries WHERE listing_id = ? AND student_id = ? AND status = 'new'", [$listingId, $u->id])) {
            throw ValidationException::single('message', 'You already have a pending enquiry for this listing.');
        }
        $limit = (int)$this->app->settings()->get('rules.enquiries_per_day', 20);
        if ((int)$db->fetchColumn('SELECT COUNT(*) FROM enquiries WHERE student_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY', [$u->id]) >= $limit) {
            throw ValidationException::single('message', "You can send up to $limit enquiries per day.");
        }
        $id = $db->insert('enquiries', ['listing_id' => $listingId, 'student_id' => $u->id, 'owner_id' => (int)$l['user_id'], 'kind' => $kind, 'message' => $message, 'viewing_at' => $when]);
        $this->app->make(Notifier::class)->notify((int)$l['user_id'], 'enquiry', ($kind === 'viewing' ? 'Viewing request for ' : 'New enquiry for ') . $l['title'], '/enquiries');
        $this->app->make(AuditLog::class)->log('enquiry.created', $u->id, 'listing', $listingId);
        return $id;
    }

    public function received(User $u): array
    {
        return $this->app->db()->fetchAll("SELECT e.*, l.title, l.slug, p.display_name AS student_name, p.photo_path AS student_photo,
                EXISTS (SELECT 1 FROM verifications v WHERE v.user_id = e.student_id AND v.status = 'approved') AS student_verified
            FROM enquiries e JOIN listings l ON l.id = e.listing_id LEFT JOIN profiles p ON p.user_id = e.student_id
            WHERE e.owner_id = ? AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = e.owner_id AND b.blocked_id = e.student_id) OR (b.blocker_id = e.student_id AND b.blocked_id = e.owner_id))
            ORDER BY e.status = 'new' DESC, e.created_at DESC LIMIT 200", [$u->id]);
    }

    public function sent(User $u): array
    {
        return $this->app->db()->fetchAll('SELECT e.*, l.title, l.slug, l.status AS listing_status FROM enquiries e JOIN listings l ON l.id = e.listing_id WHERE e.student_id = ? ORDER BY e.created_at DESC LIMIT 200', [$u->id]);
    }

    /** @param string $decision accept | decline */
    public function respond(User $u, int $id, string $decision): ?int
    {
        $db = $this->app->db();
        $e = $db->fetch("SELECT * FROM enquiries WHERE id = ? AND owner_id = ? AND status = 'new'", [$id, $u->id]);
        if (!$e) {
            throw new HttpException(404);
        }
        if (!$u->canInteract()) {
            throw new HttpException(403);
        }
        if ($decision === 'decline') {
            $db->update('enquiries', ['status' => 'declined'], 'id = ?', [$id]);
            $this->app->make(Notifier::class)->notify((int)$e['student_id'], 'enquiry', 'Your enquiry was declined. Keep looking — new places are added every day.', '/enquiries?tab=sent');
            return null;
        }
        if ($decision !== 'accept') {
            throw new HttpException(400);
        }
        if ($this->app->make(SafetyService::class)->blockedEitherWay($u->id, (int)$e['student_id'])) {
            throw new HttpException(404);
        }
        $convId = $db->transaction(function (Database $db) use ($e): int {
            $convId = $this->app->make(ChatService::class)->openConversation('listing', (int)$e['owner_id'], (int)$e['student_id'], null, (int)$e['listing_id']);
            $db->update('enquiries', ['status' => 'accepted', 'conversation_id' => $convId], 'id = ?', [$e['id']]);
            // Seed the conversation with the enquiry so both sides have context.
            if (!$db->fetchColumn('SELECT 1 FROM messages WHERE conversation_id = ?', [$convId])) {
                $db->insert('messages', ['conversation_id' => $convId, 'sender_id' => (int)$e['student_id'], 'body' => mb_substr(($e['kind'] === 'viewing' ? '[Viewing request ' . fmt_dt($e['viewing_at']) . "]\n" : '') . $e['message'], 0, 2000)]);
                $db->run('UPDATE conversations SET last_message_at = UTC_TIMESTAMP() WHERE id = ?', [$convId]);
            }
            return $convId;
        });
        $this->app->make(Notifier::class)->notify((int)$e['student_id'], 'enquiry', 'Your enquiry was accepted — you can chat with the owner now.', '/chat/' . $convId);
        $this->app->make(AuditLog::class)->log('enquiry.accepted', $u->id, 'enquiry', $id);
        return $convId;
    }

    public function cancel(User $u, int $id): void
    {
        if ($this->app->db()->update('enquiries', ['status' => 'cancelled'], "id = ? AND student_id = ? AND status = 'new'", [$id, $u->id]) === 0) {
            throw new HttpException(404);
        }
    }
}
