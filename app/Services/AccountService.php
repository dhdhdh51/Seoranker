<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\Database;
use Flatmates\Core\ValidationException;
use Flatmates\Security\Auth;
use Flatmates\Security\User;

/** Data export, password change and account deletion (7-day grace period, then anonymised by cron). */
final class AccountService
{
    public const GRACE_DAYS = 7;

    public function __construct(private App $app)
    {
    }

    /** Everything we hold about the student, as a JSON-serialisable array. */
    public function export(User $u): array
    {
        $db = $this->app->db();
        $account = $db->fetch('SELECT id, name, email, role, status, date_of_birth, email_verified_at, last_login_at, created_at, deletion_requested_at FROM users WHERE id = ?', [$u->id]);
        $convIds = array_map('intval', array_column($db->fetchAll('SELECT id FROM conversations WHERE user_a = ? OR user_b = ?', [$u->id, $u->id]), 'id'));
        $this->app->make(AuditLog::class)->log('account.exported', $u->id, 'user', $u->id);
        return [
            'exported_at' => gmdate('c'),
            'service' => brand(),
            'account' => $account,
            'profile' => $db->fetch('SELECT * FROM profiles WHERE user_id = ?', [$u->id]),
            'verifications' => $db->fetchAll('SELECT method, college_email, document_type, status, note, created_at, reviewed_at FROM verifications WHERE user_id = ?', [$u->id]),
            'swipes' => $db->fetchAll('SELECT target_id, action, created_at FROM swipes WHERE swiper_id = ?', [$u->id]),
            'matches' => $db->fetchAll('SELECT id, IF(user_a = ?, user_b, user_a) AS other_user_id, score, created_at, unmatched_at FROM matches WHERE user_a = ? OR user_b = ?', [$u->id, $u->id, $u->id]),
            'messages' => $convIds ? $db->fetchAll('SELECT conversation_id, IF(sender_id = ?, \'me\', \'other\') AS sender, IF(hidden_at IS NULL, body, \'[removed by moderator]\') AS body, created_at FROM messages WHERE conversation_id IN (' . Database::in($convIds) . ') ORDER BY id', array_merge([$u->id], $convIds)) : [],
            'listings' => $db->fetchAll('SELECT * FROM listings WHERE user_id = ?', [$u->id]),
            'enquiries_sent' => $db->fetchAll('SELECT listing_id, kind, message, viewing_at, status, created_at FROM enquiries WHERE student_id = ?', [$u->id]),
            'enquiries_received' => $db->fetchAll('SELECT listing_id, kind, message, viewing_at, status, created_at FROM enquiries WHERE owner_id = ?', [$u->id]),
            'saved' => $db->fetchAll('SELECT item_type, item_id, created_at FROM saved_items WHERE user_id = ?', [$u->id]),
            'blocked_users' => $db->fetchAll('SELECT blocked_id, created_at FROM blocks WHERE blocker_id = ?', [$u->id]),
            'reports_made' => $db->fetchAll('SELECT target_type, target_id, reason, details, status, created_at FROM reports WHERE reporter_id = ?', [$u->id]),
            'notifications' => $db->fetchAll('SELECT type, title, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 500', [$u->id]),
            'security_log' => $db->fetchAll('SELECT action, created_at FROM audit_logs WHERE actor_id = ? ORDER BY id DESC LIMIT 500', [$u->id]),
        ];
    }

    public function changePassword(User $u, string $current, string $new, string $confirm): void
    {
        $hash = (string)$this->app->db()->fetchColumn('SELECT password_hash FROM users WHERE id = ?', [$u->id]);
        if (!password_verify($current, $hash)) {
            throw ValidationException::single('current_password', 'Your current password is not correct.');
        }
        if ($p = Auth::passwordProblem($new, $u->email)) {
            throw ValidationException::single('password', $p);
        }
        if ($new !== $confirm) {
            throw ValidationException::single('password_confirmation', 'Passwords do not match.');
        }
        $this->app->db()->run('UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP() WHERE id = ?', [Auth::hash($new), $u->id]);
        $this->app->make(AuditLog::class)->log('auth.password_changed', $u->id, 'user', $u->id);
        $this->app->make(Auth::class)->startSession($u->id); // other sessions are invalidated by the password marker
    }

    public function requestDeletion(User $u, string $password): void
    {
        $hash = (string)$this->app->db()->fetchColumn('SELECT password_hash FROM users WHERE id = ?', [$u->id]);
        if (!password_verify($password, $hash)) {
            throw ValidationException::single('password', 'Enter your password to confirm.');
        }
        if ($u->isAdmin() && (int)$this->app->db()->fetchColumn("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'active'") <= 1) {
            throw ValidationException::single('password', 'You are the only Super Admin. Promote someone else first.');
        }
        $db = $this->app->db();
        $db->transaction(function (Database $db) use ($u): void {
            $db->run('UPDATE users SET deletion_requested_at = UTC_TIMESTAMP() WHERE id = ?', [$u->id]);
            $db->run('UPDATE profiles SET discoverable = 0 WHERE user_id = ?', [$u->id]);
            $db->run("UPDATE listings SET status = 'closed' WHERE user_id = ? AND status IN ('draft','pending','published')", [$u->id]);
        });
        $this->app->make(AuditLog::class)->log('account.deletion_requested', $u->id, 'user', $u->id);
        $this->app->make(Mailer::class)->send($u->email, 'Your account will be deleted', 'We received a request to delete your ' . brand() . ' account. It will be permanently deleted in ' . self::GRACE_DAYS . " days.\n\nChanged your mind? Sign in and cancel the deletion from Account settings before then.");
    }

    public function cancelDeletion(User $u): void
    {
        $this->app->db()->run('UPDATE users SET deletion_requested_at = NULL WHERE id = ?', [$u->id]);
        $this->app->make(AuditLog::class)->log('account.deletion_cancelled', $u->id, 'user', $u->id);
    }

    /** Cron: anonymise accounts whose grace period is over. Returns number processed. */
    public function processDeletions(int $limit = 20): int
    {
        $ids = $this->app->db()->fetchAll('SELECT id FROM users WHERE deletion_requested_at IS NOT NULL AND deletion_requested_at < UTC_TIMESTAMP() - INTERVAL ? DAY AND status <> \'deleted\' LIMIT ' . max(1, $limit), [self::GRACE_DAYS]);
        foreach ($ids as $r) {
            $this->anonymise((int)$r['id']);
        }
        return count($ids);
    }

    /**
     * Remove personal data while keeping integrity for other users: the account row is anonymised,
     * profile, photos, listings, documents, swipes and saved items are deleted, sent messages are
     * erased except those under an open safety report (kept for moderators until resolved).
     */
    public function anonymise(int $userId): void
    {
        $db = $this->app->db();
        $images = $this->app->make(ImageService::class);
        $files = array_merge(
            array_column($db->fetchAll('SELECT photo_path AS p FROM profiles WHERE user_id = ? AND photo_path IS NOT NULL', [$userId]), 'p'),
            array_column($db->fetchAll('SELECT lp.path AS p FROM listing_photos lp JOIN listings l ON l.id = lp.listing_id WHERE l.user_id = ?', [$userId]), 'p'),
            array_column($db->fetchAll('SELECT document_path AS p FROM verifications WHERE user_id = ? AND document_path IS NOT NULL', [$userId]), 'p'),
        );
        $db->transaction(function (Database $db) use ($userId): void {
            $db->run("DELETE m FROM messages m WHERE m.sender_id = ? AND NOT EXISTS (SELECT 1 FROM reports r WHERE r.target_type = 'message' AND r.target_id = m.id AND r.status IN ('open','escalated'))", [$userId]);
            $db->run('UPDATE matches SET unmatched_at = COALESCE(unmatched_at, UTC_TIMESTAMP()), unmatched_by = COALESCE(unmatched_by, ?) WHERE user_a = ? OR user_b = ?', [$userId, $userId, $userId]);
            foreach (['DELETE FROM swipes WHERE swiper_id = ? OR target_id = ?', 'DELETE FROM saved_items WHERE user_id = ? OR (item_type = \'profile\' AND item_id = ?)'] as $sql) {
                $db->run($sql, [$userId, $userId]);
            }
            foreach (['DELETE FROM listings WHERE user_id = ?', 'DELETE FROM verifications WHERE user_id = ?', 'DELETE FROM notifications WHERE user_id = ?', 'DELETE FROM otp_codes WHERE user_id = ?', 'DELETE FROM enquiries WHERE student_id = ?'] as $sql) {
                $db->run($sql, [$userId]);
            }
            $db->run("UPDATE profiles SET display_name = 'Deleted user', photo_path = NULL, gender = NULL, college_id = NULL, course = NULL, academic_year = NULL, locality = NULL, bio = NULL, languages = NULL, accessibility = NULL, discoverable = 0, visibility = 'matches' WHERE user_id = ?", [$userId]);
            $db->run("UPDATE users SET name = 'Deleted user', email = CONCAT('deleted-', id, '@deleted.invalid'), password_hash = ?, status = 'deleted', permissions = NULL, date_of_birth = NULL, last_login_ip = NULL, status_reason = 'Deleted at the user''s request' WHERE id = ?", [password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $userId]);
        });
        foreach ($files as $f) {
            $images->delete($f);
        }
        $this->app->make(AuditLog::class)->log('account.deleted', null, 'user', $userId);
    }
}
