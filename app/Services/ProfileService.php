<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\HttpException;
use Flatmates\Core\ValidationException;
use Flatmates\Security\Auth;
use Flatmates\Security\User;
use Flatmates\Support\Options;

/** Student profiles, 4-step onboarding, completeness and privacy-aware public views. */
final class ProfileService
{
    public const STEPS = [1 => 'Basics', 2 => 'College & budget', 3 => 'Lifestyle', 4 => 'Photo & privacy'];

    /** Fields counted for completeness. */
    private const COMPLETENESS = ['display_name', 'photo_path', 'college_id', 'course', 'city_id', 'locality', 'accommodation_status', 'move_in_date', 'budget_max', 'bio', 'languages', 'diet', 'smoking', 'alcohol', 'cleanliness', 'sleep_schedule', 'study_habits', 'social_level', 'guests', 'room_type'];

    public function __construct(private App $app)
    {
    }

    public function get(int $userId): ?array
    {
        return $this->app->db()->fetch('SELECT p.*, c.name AS city_name, c.slug AS city_slug, co.name AS college_name, co.slug AS college_slug FROM profiles p LEFT JOIN cities c ON c.id = p.city_id LEFT JOIN colleges co ON co.id = p.college_id WHERE p.user_id = ?', [$userId]);
    }

    public function ensure(User $u): array
    {
        $p = $this->get($u->id);
        if ($p) {
            return $p;
        }
        $first = (string)strtok($u->name, ' ');
        $this->app->db()->insert('profiles', ['user_id' => $u->id, 'display_name' => mb_substr($first ?: 'Student', 0, 60), 'field_visibility' => json_encode(array_fill_keys(Options::HIDDEN_BY_DEFAULT, false))]);
        return $this->get($u->id);
    }

    private static function enum(array $in, string $field, string $group, array &$out, array &$errors, bool $required = false): void
    {
        $v = trim((string)($in[$field] ?? ''));
        if ($v === '') {
            if ($required) {
                $errors[$field] = 'Please choose an option.';
            }
            $out[$field] = null;
            return;
        }
        if (!in_array($v, Options::values($group), true)) {
            $errors[$field] = 'Invalid choice.';
            return;
        }
        $out[$field] = $v;
    }

    public function saveStep(User $u, int $step, array $in, ?array $photo = null): void
    {
        $this->ensure($u);
        $db = $this->app->db();
        $out = [];
        $errors = [];
        switch ($step) {
            case 1:
                $name = trim((string)($in['display_name'] ?? ''));
                if (mb_strlen($name) < 2 || mb_strlen($name) > 60 || preg_match('/[<>@\x00-\x1F]|https?:|\d{6,}/i', $name)) {
                    $errors['display_name'] = 'Use a first name or nickname (2–60 characters, no contact details).';
                }
                $out['display_name'] = $name;
                self::enum($in, 'gender', 'gender', $out, $errors);
                self::enum($in, 'flatmate_gender_pref', 'flatmate_gender_pref', $out, $errors, true);
                self::enum($in, 'accommodation_status', 'accommodation_status', $out, $errors, true);
                $bio = trim(str_replace("\r", '', (string)($in['bio'] ?? '')));
                if (mb_strlen($bio) > 1000) {
                    $errors['bio'] = 'Keep your bio under 1000 characters.';
                }
                if (preg_match('/(\+?91[\s-]?)?[6-9]\d{9}|[\w.+-]+@[\w-]+\.\w+/', $bio)) {
                    $errors['bio'] = 'Please do not put phone numbers or email addresses in your bio — chat inside the app instead.';
                }
                $out['bio'] = $bio ?: null;
                $langs = array_values(array_intersect(Options::LANGUAGES, (array)($in['languages'] ?? [])));
                $out['languages'] = $langs ? implode(',', $langs) : null;
                if (($out['flatmate_gender_pref'] ?? null) === 'same' && (empty($out['gender']) || $out['gender'] === 'prefer_not')) {
                    $errors['gender'] = 'To match with the same gender only, tell us your gender (you can keep it hidden on your profile).';
                }
                break;
            case 2:
                $city = (int)($in['city_id'] ?? 0);
                if (!$db->fetchColumn('SELECT 1 FROM cities WHERE id = ? AND active = 1', [$city])) {
                    $errors['city_id'] = 'Choose a city.';
                }
                $out['city_id'] = $city ?: null;
                $college = (int)($in['college_id'] ?? 0);
                $out['college_id'] = $college && $db->fetchColumn('SELECT 1 FROM colleges WHERE id = ?', [$college]) ? $college : null;
                $out['course'] = mb_substr(trim((string)($in['course'] ?? '')), 0, 120) ?: null;
                $yr = (int)($in['academic_year'] ?? 0);
                $out['academic_year'] = $yr >= 1 && $yr <= 8 ? $yr : null;
                $out['locality'] = mb_substr(trim((string)($in['locality'] ?? '')), 0, 120) ?: null;
                $min = (int)($in['budget_min'] ?? 0);
                $max = (int)($in['budget_max'] ?? 0);
                if ($max < 1000 || $max > 200000 || $min > $max) {
                    $errors['budget_max'] = 'Enter a monthly budget between ₹1,000 and ₹2,00,000 (minimum ≤ maximum).';
                }
                $out['budget_min'] = $min ?: null;
                $out['budget_max'] = $max ?: null;
                $date = (string)($in['move_in_date'] ?? '');
                $out['move_in_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
                $fc = (int)($in['flatmates_count'] ?? 0);
                $out['flatmates_count'] = $fc >= 1 && $fc <= 6 ? $fc : null;
                self::enum($in, 'room_type', 'room_type', $out, $errors);
                self::enum($in, 'ac_pref', 'ac_pref', $out, $errors);
                $out['accessibility'] = mb_substr(trim((string)($in['accessibility'] ?? '')), 0, 255) ?: null;
                break;
            case 3:
                foreach (['diet' => 'diet', 'diet_pref' => 'diet_pref', 'smoking' => 'smoking', 'alcohol' => 'alcohol', 'sleep_schedule' => 'sleep_schedule', 'study_habits' => 'study_habits', 'social_level' => 'social_level', 'guests' => 'guests'] as $f => $g) {
                    self::enum($in, $f, $g, $out, $errors);
                }
                $c = (int)($in['cleanliness'] ?? 0);
                $out['cleanliness'] = $c >= 1 && $c <= 5 ? $c : null;
                $out['smoking_ok'] = isset($in['smoking_ok']) ? (int)!empty($in['smoking_ok']) : null;
                $out['alcohol_ok'] = isset($in['alcohol_ok']) ? (int)!empty($in['alcohol_ok']) : null;
                break;
            case 4:
                if ($photo && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $old = $this->get($u->id)['photo_path'] ?? null;
                    $out['photo_path'] = $this->app->make(ImageService::class)->store($photo, 'photos', 900);
                    $this->app->make(ImageService::class)->delete($old);
                }
                $out['discoverable'] = (int)!empty($in['discoverable']);
                $out['visibility'] = in_array($in['visibility'] ?? '', ['members', 'matches'], true) ? $in['visibility'] : 'members';
                $vis = [];
                foreach (array_keys(Options::PRIVATE_FIELDS) as $f) {
                    $vis[$f] = !empty($in['show_' . $f]);
                }
                $out['field_visibility'] = json_encode($vis);
                break;
            default:
                throw new HttpException(404);
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $current = $this->get($u->id);
        $out['onboarding_step'] = max((int)$current['onboarding_step'], min(5, $step + 1));
        if ($step === 4 && !$current['onboarded_at']) {
            $out['onboarded_at'] = gmdate('Y-m-d H:i:s');
        }
        $db->update('profiles', $out, 'user_id = ?', [$u->id]);
        $db->update('profiles', ['completeness' => self::completeness($this->get($u->id))], 'user_id = ?', [$u->id]);
    }

    public static function completeness(array $p): int
    {
        $filled = 0;
        foreach (self::COMPLETENESS as $f) {
            if (($p[$f] ?? null) !== null && $p[$f] !== '') {
                $filled++;
            }
        }
        return (int)round($filled / count(self::COMPLETENESS) * 100);
    }

    public static function isVisible(array $p, string $field): bool
    {
        $vis = json_decode((string)($p['field_visibility'] ?? ''), true) ?: [];
        return array_key_exists($field, $vis) ? (bool)$vis[$field] : !in_array($field, Options::HIDDEN_BY_DEFAULT, true);
    }

    /**
     * Profile as another student may see it, or null when not allowed.
     * Never includes legal name, email, phone, DOB, documents or exact address.
     */
    public function viewFor(User $viewer, int $targetId): ?array
    {
        $db = $this->app->db();
        $row = $db->fetch("SELECT p.*, u.date_of_birth, u.status, u.role, c.name AS city_name, co.name AS college_name,
                (SELECT COUNT(*) FROM verifications v WHERE v.user_id = u.id AND v.status = 'approved') AS verified
            FROM profiles p JOIN users u ON u.id = p.user_id LEFT JOIN cities c ON c.id = p.city_id LEFT JOIN colleges co ON co.id = p.college_id
            WHERE p.user_id = ? AND u.status IN ('active','restricted') AND u.role = 'student'", [$targetId]);
        if (!$row) {
            return null;
        }
        $self = $viewer->id === $targetId;
        if (!$self && !$viewer->isStaff()) {
            if ($this->app->make(SafetyService::class)->blockedEitherWay($viewer->id, $targetId)) {
                return null;
            }
            $matched = $this->app->make(MatchService::class)->activeMatch($viewer->id, $targetId) !== null;
            if ($row['visibility'] === 'matches' && !$matched) {
                return null;
            }
            if (!$row['discoverable'] && !$matched) {
                return null;
            }
        }
        return self::present($row, $self);
    }

    /** Build the privacy-filtered card/profile array from a joined profile row. */
    public static function present(array $row, bool $self): array
    {
        $public = [
            'user_id' => (int)$row['user_id'], 'display_name' => $row['display_name'], 'photo_path' => $row['photo_path'],
            'college_name' => $row['college_name'] ?? null, 'city_name' => $row['city_name'] ?? null, 'locality' => $row['locality'],
            'accommodation_status' => $row['accommodation_status'], 'move_in_date' => $row['move_in_date'],
            'budget_min' => $row['budget_min'], 'budget_max' => $row['budget_max'], 'flatmates_count' => $row['flatmates_count'],
            'bio' => $row['bio'], 'cleanliness' => $row['cleanliness'], 'sleep_schedule' => $row['sleep_schedule'],
            'study_habits' => $row['study_habits'], 'social_level' => $row['social_level'], 'guests' => $row['guests'],
            'ac_pref' => $row['ac_pref'], 'room_type' => $row['room_type'], 'diet_pref' => $row['diet_pref'],
            'verified' => (int)($row['verified'] ?? 0) > 0, 'completeness' => (int)$row['completeness'],
        ];
        $show = static fn(string $f): bool => $self || self::isVisible($row, $f);
        if ($show('gender')) {
            $public['gender'] = $row['gender'];
        }
        if ($show('age') && !empty($row['date_of_birth'])) {
            $public['age'] = Auth::age((string)$row['date_of_birth']);
        }
        if ($show('course')) {
            $public['course'] = $row['course'];
            $public['academic_year'] = $row['academic_year'];
        }
        foreach (['languages', 'diet', 'smoking', 'alcohol'] as $f) {
            if ($show($f)) {
                $public[$f] = $row[$f];
            }
        }
        return $public;
    }

    public function cities(): array
    {
        return $this->app->db()->fetchAll('SELECT * FROM cities WHERE active = 1 ORDER BY name');
    }

    public function colleges(?int $cityId = null): array
    {
        return $cityId
            ? $this->app->db()->fetchAll('SELECT * FROM colleges WHERE active = 1 AND city_id = ? ORDER BY name', [$cityId])
            : $this->app->db()->fetchAll('SELECT co.*, c.name AS city_name FROM colleges co JOIN cities c ON c.id = co.city_id WHERE co.active = 1 ORDER BY c.name, co.name');
    }
}
