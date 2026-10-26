<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\Database;

/**
 * Trust badges. Every badge is derived from a real record (never from user-supplied text):
 *  - email_verified   users.email_verified_at (account email confirmed by one-time code)
 *  - college_email    an approved 'college_email' verification (code sent to a college-domain mailbox)
 *  - student_id       an approved 'document' verification reviewed by a moderator
 *  - listing_reviewed listings.reviewed_at set when a moderator approved the current version
 */
final class TrustService
{
    public const BADGES = [
        'email_verified' => [
            'label' => 'Email Verified', 'icon' => 'envelope-check', 'tone' => 'neutral',
            'means' => 'The person confirmed they can receive email at the address they signed up with.',
            'not' => 'It does not confirm their name, that they are a student, or anything about their character.',
        ],
        'college_email' => [
            'label' => 'College Email Verified', 'icon' => 'mortarboard', 'tone' => 'success',
            'means' => 'The person entered a one-time code we sent to an address on a college email domain listed by our team.',
            'not' => 'It does not prove current enrolment (some alumni and staff keep college addresses) or confirm their identity.',
        ],
        'student_id' => [
            'label' => 'Student ID Reviewed', 'icon' => 'person-vcard', 'tone' => 'success',
            'means' => 'A moderator looked at a student document they uploaded and judged that it matched their account name and showed an enrolment. The document was then deleted.',
            'not' => 'Moderators cannot detect every forgery. It is not a background check and does not guarantee safety.',
        ],
        'listing_reviewed' => [
            'label' => 'Listing Reviewed', 'icon' => 'clipboard-check', 'tone' => 'success',
            'means' => 'A moderator read this version of the listing and photos and found nothing that breaks our rules.',
            'not' => 'We have not visited the property, inspected it, or checked who owns it or who is allowed to rent it out.',
        ],
    ];

    public function __construct(private App $app)
    {
    }

    /**
     * Badges for many users at once (avoids N+1 queries).
     * @param int[] $userIds
     * @return array<int,string[]> user id => badge keys
     */
    public function forUsers(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if (!$ids) {
            return [];
        }
        $db = $this->app->db();
        $out = array_fill_keys($ids, []);
        foreach ($db->fetchAll('SELECT id FROM users WHERE email_verified_at IS NOT NULL AND id IN (' . Database::in($ids) . ')', $ids) as $r) {
            $out[(int)$r['id']][] = 'email_verified';
        }
        foreach ($db->fetchAll("SELECT DISTINCT user_id, method FROM verifications WHERE status = 'approved' AND user_id IN (" . Database::in($ids) . ')', $ids) as $r) {
            $out[(int)$r['user_id']][] = $r['method'] === 'college_email' ? 'college_email' : 'student_id';
        }
        foreach ($out as &$b) {
            $b = array_values(array_intersect(array_keys(self::BADGES), array_unique($b)));
        }
        return $out;
    }

    /** @return string[] */
    public function forUser(int $userId): array
    {
        return $this->forUsers([$userId])[$userId] ?? [];
    }

    public static function isStudentVerified(array $badges): bool
    {
        return in_array('college_email', $badges, true) || in_array('student_id', $badges, true);
    }

    /** @return string[] badge keys for a listing row (needs status + reviewed_at) */
    public static function forListing(array $l): array
    {
        return ($l['status'] ?? '') === 'published' && !empty($l['reviewed_at']) ? ['listing_reviewed'] : [];
    }
}
