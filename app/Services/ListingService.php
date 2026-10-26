<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\Database;
use Flatmates\Core\HttpException;
use Flatmates\Core\ValidationException;
use Flatmates\Security\User;
use Flatmates\Support\Options;

/** Flat / shared-room / vacant-bed listings, photos, moderation, search and saved items. */
final class ListingService
{
    public const MAX_PHOTOS = 8;
    public const PER_PAGE = 12;
    public const SORTS = ['newest' => 'Newest first', 'rent_asc' => 'Rent: low to high', 'rent_desc' => 'Rent: high to low'];

    public function __construct(private App $app)
    {
    }

    public static function slugify(string $s): string
    {
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s) ?? '', '-'));
        return substr($s ?: 'listing', 0, 120);
    }

    private function validate(array $in): array
    {
        $db = $this->app->db();
        $e = [];
        $o = [];
        $o['type'] = in_array($in['type'] ?? '', Options::values('listing_type'), true) ? $in['type'] : null;
        if (!$o['type']) {
            $e['type'] = 'Choose what you are listing.';
        }
        $o['title'] = trim((string)($in['title'] ?? ''));
        if (mb_strlen($o['title']) < 8 || mb_strlen($o['title']) > 140) {
            $e['title'] = 'Title must be 8–140 characters.';
        }
        $o['city_id'] = (int)($in['city_id'] ?? 0);
        if (!$db->fetchColumn('SELECT 1 FROM cities WHERE id = ? AND active = 1', [$o['city_id']])) {
            $e['city_id'] = 'Choose a city.';
        }
        $o['locality'] = mb_substr(trim((string)($in['locality'] ?? '')), 0, 120);
        if (mb_strlen($o['locality']) < 2) {
            $e['locality'] = 'Enter the locality / sector (e.g. Sector 62).';
        }
        $o['address_private'] = mb_substr(trim((string)($in['address_private'] ?? '')), 0, 300) ?: null;
        $col = (int)($in['near_college_id'] ?? 0);
        $o['near_college_id'] = $col && $db->fetchColumn('SELECT 1 FROM colleges WHERE id = ? AND city_id = ?', [$col, $o['city_id']]) ? $col : null;
        $o['rent'] = (int)($in['rent'] ?? 0);
        if ($o['rent'] < 500 || $o['rent'] > 500000) {
            $e['rent'] = 'Monthly rent must be between ₹500 and ₹5,00,000.';
        }
        $dep = trim((string)($in['deposit'] ?? ''));
        $o['deposit'] = $dep === '' ? null : (int)$dep;
        if ($o['deposit'] !== null && ($o['deposit'] < 0 || $o['deposit'] > 2000000)) {
            $e['deposit'] = 'Deposit looks wrong.';
        }
        $d = (string)($in['available_from'] ?? '');
        $o['available_from'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : null;
        $o['furnishing'] = in_array($in['furnishing'] ?? '', Options::values('furnishing'), true) ? $in['furnishing'] : null;
        $o['gender_pref'] = in_array($in['gender_pref'] ?? '', Options::values('listing_gender'), true) ? $in['gender_pref'] : 'any';
        $tb = (int)($in['total_beds'] ?? 0);
        $ba = (int)($in['beds_available'] ?? 0);
        $o['total_beds'] = $tb >= 1 && $tb <= 20 ? $tb : null;
        $o['beds_available'] = $ba >= 1 && $ba <= 20 ? $ba : null;
        if ($o['total_beds'] && $o['beds_available'] && $o['beds_available'] > $o['total_beds']) {
            $e['beds_available'] = 'Available beds cannot exceed total beds.';
        }
        $am = array_values(array_intersect(array_keys(Options::AMENITIES), (array)($in['amenities'] ?? [])));
        $o['amenities'] = $am ? implode(',', $am) : null;
        $o['description'] = trim(str_replace("\r", '', (string)($in['description'] ?? '')));
        if (mb_strlen($o['description']) < 30 || mb_strlen($o['description']) > 5000) {
            $e['description'] = 'Describe the place in 30–5000 characters.';
        } elseif (preg_match('/(\+?91[\s-]?)?[6-9]\d{9}|[\w.+-]+@[\w-]+\.\w+/', $o['description'] . ' ' . $o['title'])) {
            $e['description'] = 'Please keep phone numbers and emails out of the listing — students contact you through the app, which keeps everyone safer.';
        }
        $o['visibility'] = ($in['visibility'] ?? 'public') === 'members' ? 'members' : 'public';
        if ($e) {
            throw new ValidationException($e);
        }
        return $o;
    }

    private function assertCanPost(User $u): void
    {
        if (!$u->isStudent() || !$u->canInteract()) {
            throw new HttpException(403, 'Your account cannot post listings right now.');
        }
    }

    public function create(User $u, array $in, bool $submit): int
    {
        $this->assertCanPost($u);
        $data = $this->validate($in);
        $db = $this->app->db();
        $active = (int)$db->fetchColumn("SELECT COUNT(*) FROM listings WHERE user_id = ? AND status IN ('draft','pending','published','hidden')", [$u->id]);
        $max = (int)$this->app->settings()->get('rules.listings_per_user', 5);
        if ($active >= $max) {
            throw ValidationException::single('title', "You can have up to $max open listings. Close an old one first.");
        }
        $data['user_id'] = $u->id;
        $data['slug'] = 'tmp-' . bin2hex(random_bytes(8));
        $data['status'] = 'draft';
        $id = $db->insert('listings', $data);
        $db->update('listings', ['slug' => self::slugify($data['title'] . ' ' . $data['locality']) . '-' . $id], 'id = ?', [$id]);
        $this->app->make(AuditLog::class)->log('listing.created', $u->id, 'listing', $id);
        if ($submit) {
            $this->submit($u, $id);
        }
        return $id;
    }

    public function ownListing(User $u, int $id): array
    {
        $l = $this->app->db()->fetch('SELECT * FROM listings WHERE id = ? AND user_id = ?', [$id, $u->id]);
        if (!$l) {
            throw new HttpException(404);
        }
        return $l;
    }

    public function update(User $u, int $id, array $in, bool $submit): void
    {
        $this->assertCanPost($u);
        $l = $this->ownListing($u, $id);
        $data = $this->validate($in);
        $review = $this->app->settings()->bool('rules.listing_review');
        if ($l['status'] === 'published' && $review) {
            $data['status'] = 'pending'; // edits are re-checked before going live again
        }
        $this->app->db()->update('listings', $data, 'id = ?', [$id]);
        $this->app->make(AuditLog::class)->log('listing.updated', $u->id, 'listing', $id);
        if ($submit && in_array($l['status'], ['draft', 'rejected', 'closed'], true)) {
            $this->submit($u, $id);
        }
    }

    /** Owner sends a draft for review (or publishes directly when review is off). */
    public function submit(User $u, int $id): void
    {
        $this->assertCanPost($u);
        $l = $this->ownListing($u, $id);
        if ($l['status'] === 'hidden') {
            throw new HttpException(403, 'A moderator hid this listing. Contact support if you think this is a mistake.');
        }
        if ($this->app->settings()->bool('rules.listing_review')) {
            $this->app->db()->update('listings', ['status' => 'pending'], 'id = ?', [$id]);
            $this->app->make(Notifier::class)->notifyStaff('listings', 'Listing waiting for review: ' . $l['title'], '/admin/listings?status=pending');
        } else {
            $this->app->db()->run("UPDATE listings SET status = 'published', published_at = COALESCE(published_at, UTC_TIMESTAMP()) WHERE id = ?", [$id]);
        }
    }

    public function close(User $u, int $id): void
    {
        $this->ownListing($u, $id);
        $this->app->db()->update('listings', ['status' => 'closed'], 'id = ?', [$id]);
        $this->app->make(AuditLog::class)->log('listing.closed', $u->id, 'listing', $id);
    }

    public function delete(User $u, int $id): void
    {
        $this->ownListing($u, $id);
        foreach ($this->photos($id) as $p) {
            $this->app->make(ImageService::class)->delete($p['path']);
        }
        $this->app->db()->run('DELETE FROM listings WHERE id = ?', [$id]);
        $this->app->make(AuditLog::class)->log('listing.deleted', $u->id, 'listing', $id);
    }

    public function photos(int $listingId): array
    {
        return $this->app->db()->fetchAll('SELECT * FROM listing_photos WHERE listing_id = ? ORDER BY sort, id', [$listingId]);
    }

    /** @param array<int,array> $files normalised $_FILES entries */
    public function addPhotos(User $u, int $id, array $files): int
    {
        $this->assertCanPost($u);
        $this->ownListing($u, $id);
        $db = $this->app->db();
        $count = (int)$db->fetchColumn('SELECT COUNT(*) FROM listing_photos WHERE listing_id = ?', [$id]);
        $added = 0;
        foreach ($files as $f) {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($count >= self::MAX_PHOTOS) {
                throw ValidationException::single('photos', 'A listing can have up to ' . self::MAX_PHOTOS . ' photos.');
            }
            $path = $this->app->make(ImageService::class)->store($f, 'listings', 1600);
            $db->insert('listing_photos', ['listing_id' => $id, 'path' => $path, 'sort' => $count]);
            $count++;
            $added++;
        }
        if ($added && $this->app->settings()->bool('rules.listing_review') && $db->fetchColumn("SELECT status FROM listings WHERE id = ?", [$id]) === 'published') {
            $db->update('listings', ['status' => 'pending'], 'id = ?', [$id]);
        }
        return $added;
    }

    public function deletePhoto(User $u, int $listingId, int $photoId): void
    {
        $this->ownListing($u, $listingId);
        $p = $this->app->db()->fetch('SELECT * FROM listing_photos WHERE id = ? AND listing_id = ?', [$photoId, $listingId]);
        if (!$p) {
            throw new HttpException(404);
        }
        $this->app->make(ImageService::class)->delete($p['path']);
        $this->app->db()->run('DELETE FROM listing_photos WHERE id = ?', [$photoId]);
    }

    public function mine(User $u): array
    {
        return $this->app->db()->fetchAll('SELECT l.*, c.name AS city_name, (SELECT path FROM listing_photos p WHERE p.listing_id = l.id ORDER BY sort, id LIMIT 1) AS cover,
                (SELECT COUNT(*) FROM enquiries e WHERE e.listing_id = l.id AND e.status = \'new\') AS new_enquiries
            FROM listings l JOIN cities c ON c.id = l.city_id WHERE l.user_id = ? ORDER BY l.updated_at DESC', [$u->id]);
    }

    /**
     * Public/member search over published listings.
     * @return array{rows:array, total:int}
     */
    public function search(array $f, int $page = 1, ?User $viewer = null, int $perPage = self::PER_PAGE): array
    {
        $where = ["l.status = 'published'", "u.status = 'active'"];
        $params = [];
        if (!$viewer) {
            $where[] = "l.visibility = 'public'";
        } else {
            $where[] = 'NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = ? AND b.blocked_id = l.user_id) OR (b.blocker_id = l.user_id AND b.blocked_id = ?))';
            array_push($params, $viewer->id, $viewer->id);
        }
        if (!empty($f['city_id'])) {
            $where[] = 'l.city_id = ?';
            $params[] = (int)$f['city_id'];
        }
        if (!empty($f['college_id'])) {
            $where[] = 'l.near_college_id = ?';
            $params[] = (int)$f['college_id'];
        }
        if (!empty($f['type']) && in_array($f['type'], Options::values('listing_type'), true)) {
            $where[] = 'l.type = ?';
            $params[] = $f['type'];
        }
        if (!empty($f['min_rent'])) {
            $where[] = 'l.rent >= ?';
            $params[] = (int)$f['min_rent'];
        }
        if (!empty($f['max_rent'])) {
            $where[] = 'l.rent <= ?';
            $params[] = (int)$f['max_rent'];
        }
        if (!empty($f['gender']) && in_array($f['gender'], ['female', 'male'], true)) {
            $where[] = "l.gender_pref IN ('any', ?)";
            $params[] = $f['gender'];
        }
        if (!empty($f['furnishing']) && in_array($f['furnishing'], Options::values('furnishing'), true)) {
            $where[] = 'l.furnishing = ?';
            $params[] = $f['furnishing'];
        }
        if (!empty($f['available_by']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['available_by'])) {
            $where[] = '(l.available_from IS NULL OR l.available_from <= ?)';
            $params[] = $f['available_by'];
        }
        foreach (array_intersect(array_keys(Options::AMENITIES), (array)($f['amenities'] ?? [])) as $a) {
            $where[] = 'FIND_IN_SET(?, l.amenities)';
            $params[] = $a;
        }
        if (!empty($f['q'])) {
            $q = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_substr(trim((string)$f['q']), 0, 80)) . '%';
            $where[] = '(l.title LIKE ? OR l.locality LIKE ?)';
            array_push($params, $q, $q);
        }
        $order = match ($f['sort'] ?? 'newest') {
            'rent_asc' => 'l.rent ASC, l.id DESC',
            'rent_desc' => 'l.rent DESC, l.id DESC',
            default => 'l.published_at DESC, l.id DESC',
        };
        $sqlWhere = implode(' AND ', $where);
        $db = $this->app->db();
        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM listings l JOIN users u ON u.id = l.user_id WHERE $sqlWhere", $params);
        $page = max(1, $page);
        $rows = $db->fetchAll("SELECT l.id, l.type, l.title, l.slug, l.locality, l.rent, l.deposit, l.available_from, l.furnishing, l.gender_pref, l.beds_available, l.amenities, l.published_at,
                c.name AS city_name, c.slug AS city_slug, co.name AS college_name,
                (SELECT path FROM listing_photos p WHERE p.listing_id = l.id ORDER BY sort, id LIMIT 1) AS cover,
                EXISTS (SELECT 1 FROM verifications v WHERE v.user_id = l.user_id AND v.status = 'approved') AS owner_verified
            FROM listings l JOIN users u ON u.id = l.user_id JOIN cities c ON c.id = l.city_id LEFT JOIN colleges co ON co.id = l.near_college_id
            WHERE $sqlWhere ORDER BY $order LIMIT " . (int)$perPage . ' OFFSET ' . (($page - 1) * (int)$perPage), $params);
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * A listing as $viewer may see it. Owners and listing moderators see any status; others only
     * published ones. The private address is shown to the owner, staff, and students whose
     * enquiry the owner accepted.
     */
    public function view(string $slug, ?User $viewer): array
    {
        $db = $this->app->db();
        $l = $db->fetch("SELECT l.*, c.name AS city_name, c.slug AS city_slug, co.name AS college_name, co.slug AS college_slug,
                p.display_name AS owner_name, p.photo_path AS owner_photo, u.status AS owner_status,
                EXISTS (SELECT 1 FROM verifications v WHERE v.user_id = l.user_id AND v.status = 'approved') AS owner_verified
            FROM listings l JOIN users u ON u.id = l.user_id JOIN cities c ON c.id = l.city_id LEFT JOIN colleges co ON co.id = l.near_college_id LEFT JOIN profiles p ON p.user_id = l.user_id
            WHERE l.slug = ?", [$slug]);
        if (!$l) {
            throw new HttpException(404);
        }
        $isOwner = $viewer && (int)$l['user_id'] === $viewer->id;
        $isMod = $viewer && $viewer->can('listings');
        if (!$isOwner && !$isMod) {
            if ($l['status'] !== 'published' || $l['owner_status'] !== 'active') {
                throw new HttpException(404);
            }
            if ($l['visibility'] === 'members' && !$viewer) {
                throw new HttpException(403, 'Sign in to view this listing — the owner shares it with members only.');
            }
            if ($viewer && $this->app->make(SafetyService::class)->blockedEitherWay($viewer->id, (int)$l['user_id'])) {
                throw new HttpException(404);
            }
            $db->run('UPDATE listings SET views = views + 1 WHERE id = ?', [$l['id']]);
        }
        $accepted = $viewer && !$isOwner && $db->fetchColumn("SELECT 1 FROM enquiries WHERE listing_id = ? AND student_id = ? AND status = 'accepted'", [$l['id'], $viewer->id]);
        if (!$isOwner && !$isMod && !$accepted) {
            $l['address_private'] = null;
        }
        $l['is_owner'] = $isOwner;
        $l['photos'] = $this->photos((int)$l['id']);
        $l['amenity_list'] = array_values(array_filter(explode(',', (string)$l['amenities'])));
        return $l;
    }

    // ---- moderation -------------------------------------------------------------------------

    public function moderate(User $staff, int $id, string $action, string $note = ''): void
    {
        if (!$staff->can('listings')) {
            throw new HttpException(403);
        }
        $l = $this->app->db()->fetch('SELECT * FROM listings WHERE id = ?', [$id]);
        if (!$l) {
            throw new HttpException(404);
        }
        $note = mb_substr(trim($note), 0, 500) ?: null;
        $db = $this->app->db();
        match ($action) {
            'approve' => $db->run("UPDATE listings SET status = 'published', moderation_note = NULL, published_at = COALESCE(published_at, UTC_TIMESTAMP()) WHERE id = ?", [$id]),
            'reject' => $db->update('listings', ['status' => 'rejected', 'moderation_note' => $note ?? 'Please review our listing guidelines.'], 'id = ?', [$id]),
            'hide' => $db->update('listings', ['status' => 'hidden', 'moderation_note' => $note ?? 'Hidden by a moderator.'], 'id = ?', [$id]),
            default => throw ValidationException::single('action', 'Unknown action.'),
        };
        $msg = match ($action) {
            'approve' => 'Your listing "' . $l['title'] . '" is now live.',
            'reject' => 'Your listing "' . $l['title'] . '" needs changes before it can go live.',
            default => 'A moderator hid your listing "' . $l['title'] . '".',
        };
        $this->app->make(Notifier::class)->notify((int)$l['user_id'], 'listing', $msg, '/my/listings');
        $this->app->make(AuditLog::class)->log('listing.' . $action, $staff->id, 'listing', $id, $note ? ['note' => $note] : []);
    }

    public function removePhotoAsStaff(User $staff, int $photoId): void
    {
        if (!$staff->can('listings')) {
            throw new HttpException(403);
        }
        $p = $this->app->db()->fetch('SELECT * FROM listing_photos WHERE id = ?', [$photoId]);
        if (!$p) {
            throw new HttpException(404);
        }
        $this->app->make(ImageService::class)->delete($p['path']);
        $this->app->db()->run('DELETE FROM listing_photos WHERE id = ?', [$photoId]);
        $this->app->make(AuditLog::class)->log('listing.photo_removed', $staff->id, 'listing', (int)$p['listing_id']);
    }

    public function adminList(string $status, int $page): array
    {
        $where = $status !== '' && in_array($status, Options::values('listing_status'), true) ? 'l.status = ?' : '1=1';
        $params = $where === '1=1' ? [] : [$status];
        $db = $this->app->db();
        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM listings l WHERE $where", $params);
        $rows = $db->fetchAll("SELECT l.*, c.name AS city_name, p.display_name AS owner_name, u.email AS owner_email,
                (SELECT COUNT(*) FROM reports r WHERE r.target_type = 'listing' AND r.target_id = l.id AND r.status IN ('open','escalated')) AS open_reports
            FROM listings l JOIN cities c ON c.id = l.city_id JOIN users u ON u.id = l.user_id LEFT JOIN profiles p ON p.user_id = l.user_id
            WHERE $where ORDER BY l.status = 'pending' DESC, l.updated_at DESC LIMIT 30 OFFSET " . ((max(1, $page) - 1) * 30), $params);
        return ['rows' => $rows, 'total' => $total];
    }

    // ---- saved items -------------------------------------------------------------------------

    public function toggleSave(User $u, string $type, int $id): bool
    {
        if (!in_array($type, ['listing', 'profile'], true)) {
            throw new HttpException(400);
        }
        $db = $this->app->db();
        if ($db->fetchColumn('SELECT 1 FROM saved_items WHERE user_id = ? AND item_type = ? AND item_id = ?', [$u->id, $type, $id])) {
            $db->run('DELETE FROM saved_items WHERE user_id = ? AND item_type = ? AND item_id = ?', [$u->id, $type, $id]);
            return false;
        }
        $exists = $type === 'listing'
            ? $db->fetchColumn("SELECT 1 FROM listings WHERE id = ? AND status = 'published'", [$id])
            : $db->fetchColumn("SELECT 1 FROM profiles p JOIN users u ON u.id = p.user_id WHERE p.user_id = ? AND u.status = 'active' AND p.discoverable = 1", [$id]);
        if (!$exists || ($type === 'profile' && $id === $u->id)) {
            throw new HttpException(404);
        }
        $db->run('INSERT IGNORE INTO saved_items (user_id, item_type, item_id) VALUES (?, ?, ?)', [$u->id, $type, $id]);
        return true;
    }

    public function isSaved(int $userId, string $type, int $id): bool
    {
        return (bool)$this->app->db()->fetchColumn('SELECT 1 FROM saved_items WHERE user_id = ? AND item_type = ? AND item_id = ?', [$userId, $type, $id]);
    }

    public function saved(User $u): array
    {
        $db = $this->app->db();
        $listings = $db->fetchAll("SELECT l.id, l.title, l.slug, l.rent, l.locality, l.type, l.status, c.name AS city_name,
                (SELECT path FROM listing_photos p WHERE p.listing_id = l.id ORDER BY sort, id LIMIT 1) AS cover
            FROM saved_items s JOIN listings l ON l.id = s.item_id JOIN cities c ON c.id = l.city_id
            WHERE s.user_id = ? AND s.item_type = 'listing' AND l.status = 'published' ORDER BY s.created_at DESC", [$u->id]);
        $profiles = $db->fetchAll("SELECT p.user_id, p.display_name, p.photo_path, p.budget_max, co.name AS college_name
            FROM saved_items s JOIN profiles p ON p.user_id = s.item_id JOIN users u ON u.id = p.user_id LEFT JOIN colleges co ON co.id = p.college_id
            WHERE s.user_id = ? AND s.item_type = 'profile' AND u.status = 'active' AND p.discoverable = 1
              AND NOT EXISTS (SELECT 1 FROM blocks b WHERE (b.blocker_id = ? AND b.blocked_id = p.user_id) OR (b.blocker_id = p.user_id AND b.blocked_id = ?))
            ORDER BY s.created_at DESC", [$u->id, $u->id, $u->id]);
        return ['listings' => $listings, 'profiles' => $profiles];
    }

    /** Normalise a multi-file $_FILES entry into a list of single-file arrays. */
    public static function normaliseFiles(?array $f): array
    {
        if (!$f || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return [$f];
        }
        $out = [];
        foreach (array_keys($f['name']) as $i) {
            $out[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
        }
        return $out;
    }
}
