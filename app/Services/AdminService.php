<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\HttpException;
use Flatmates\Core\ValidationException;
use Flatmates\Security\Auth;
use Flatmates\Security\User;

/** Admin dashboard data, user management, analytics and system health. */
final class AdminService
{
    public function __construct(private App $app)
    {
    }

    public function stats(): array
    {
        $db = $this->app->db();
        $one = static fn(string $sql): int => (int)$db->fetchColumn($sql);
        return [
            'students' => $one("SELECT COUNT(*) FROM users WHERE role = 'student' AND status IN ('active','restricted')"),
            'new_week' => $one("SELECT COUNT(*) FROM users WHERE role = 'student' AND created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY"),
            'active_week' => $one("SELECT COUNT(*) FROM users WHERE role = 'student' AND last_seen_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY"),
            'verified' => $one("SELECT COUNT(DISTINCT user_id) FROM verifications WHERE status = 'approved'"),
            'pending_verifications' => $one("SELECT COUNT(*) FROM verifications WHERE status = 'pending'"),
            'open_reports' => $one("SELECT COUNT(*) FROM reports WHERE status IN ('open','escalated')"),
            'serious_reports' => $one("SELECT COUNT(*) FROM reports WHERE status IN ('open','escalated') AND severity = 'serious'"),
            'listings_live' => $one("SELECT COUNT(*) FROM listings WHERE status = 'published'"),
            'listings_pending' => $one("SELECT COUNT(*) FROM listings WHERE status = 'pending'"),
            'matches_week' => $one('SELECT COUNT(*) FROM matches WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY'),
            'messages_week' => $one('SELECT COUNT(*) FROM messages WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY'),
            'mail_failed' => $one("SELECT COUNT(*) FROM mail_queue WHERE status = 'failed' AND created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY"),
            'mail_pending' => $one("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending'"),
        ];
    }

    /** Daily counts for the last $days days (signups, matches, listings, messages). */
    public function analytics(int $days = 30): array
    {
        $db = $this->app->db();
        $series = [];
        foreach (['signups' => "SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE role = 'student' AND created_at >= UTC_DATE() - INTERVAL ? DAY GROUP BY d",
                  'matches' => 'SELECT DATE(created_at) d, COUNT(*) n FROM matches WHERE created_at >= UTC_DATE() - INTERVAL ? DAY GROUP BY d',
                  'listings' => 'SELECT DATE(created_at) d, COUNT(*) n FROM listings WHERE created_at >= UTC_DATE() - INTERVAL ? DAY GROUP BY d',
                  'messages' => 'SELECT DATE(created_at) d, COUNT(*) n FROM messages WHERE created_at >= UTC_DATE() - INTERVAL ? DAY GROUP BY d'] as $k => $sql) {
            $series[$k] = $db->fetchPairs($sql, [$days]);
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $out[] = ['date' => $d] + array_map(static fn($s) => (int)($s[$d] ?? 0), $series);
        }
        $topCities = $db->fetchAll("SELECT c.name, COUNT(*) n FROM profiles p JOIN cities c ON c.id = p.city_id JOIN users u ON u.id = p.user_id WHERE u.status = 'active' GROUP BY c.id ORDER BY n DESC LIMIT 8");
        $topColleges = $db->fetchAll("SELECT co.name, COUNT(*) n FROM profiles p JOIN colleges co ON co.id = p.college_id JOIN users u ON u.id = p.user_id WHERE u.status = 'active' GROUP BY co.id ORDER BY n DESC LIMIT 8");
        $funnel = [
            'registered' => (int)$db->fetchColumn("SELECT COUNT(*) FROM users WHERE role = 'student'"),
            'email_verified' => (int)$db->fetchColumn("SELECT COUNT(*) FROM users WHERE role = 'student' AND email_verified_at IS NOT NULL"),
            'onboarded' => (int)$db->fetchColumn('SELECT COUNT(*) FROM profiles WHERE onboarded_at IS NOT NULL'),
            'matched' => (int)$db->fetchColumn('SELECT COUNT(DISTINCT u) FROM (SELECT user_a u FROM matches UNION ALL SELECT user_b FROM matches) x'),
        ];
        return ['daily' => $out, 'top_cities' => $topCities, 'top_colleges' => $topColleges, 'funnel' => $funnel];
    }

    public function users(array $f, int $page): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $q = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_substr(trim((string)$f['q']), 0, 100)) . '%';
            $where[] = '(u.name LIKE ? OR u.email LIKE ? OR p.display_name LIKE ?)';
            array_push($params, $q, $q, $q);
        }
        if (!empty($f['status']) && in_array($f['status'], ['pending', 'active', 'restricted', 'suspended', 'deleted'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['role']) && in_array($f['role'], [User::STUDENT, User::MODERATOR, User::SUPER_ADMIN], true)) {
            $where[] = 'u.role = ?';
            $params[] = $f['role'];
        }
        $w = implode(' AND ', $where);
        $db = $this->app->db();
        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE $w", $params);
        $rows = $db->fetchAll("SELECT u.id, u.name, u.email, u.role, u.status, u.created_at, u.last_seen_at, u.email_verified_at, u.deletion_requested_at, p.display_name, p.completeness,
                EXISTS (SELECT 1 FROM verifications v WHERE v.user_id = u.id AND v.status = 'approved') AS verified,
                (SELECT COUNT(*) FROM reports r WHERE r.target_user_id = u.id) AS reports_against
            FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE $w ORDER BY u.id DESC LIMIT 30 OFFSET " . ((max(1, $page) - 1) * 30), $params);
        return ['rows' => $rows, 'total' => $total];
    }

    public function user(int $id): array
    {
        $db = $this->app->db();
        $u = $db->fetch('SELECT u.id, u.name, u.email, u.role, u.permissions, u.status, u.status_reason, u.created_at, u.last_login_at, u.last_seen_at, u.email_verified_at, u.deletion_requested_at FROM users u WHERE u.id = ?', [$id]);
        if (!$u) {
            throw new HttpException(404);
        }
        $u['profile'] = $this->app->make(ProfileService::class)->get($id);
        $u['verifications'] = $this->app->make(VerificationService::class)->forUser($id);
        $u['reports_against'] = $db->fetchAll('SELECT id, target_type, reason, status, severity, created_at FROM reports WHERE target_user_id = ? ORDER BY id DESC LIMIT 50', [$id]);
        $u['reports_made'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM reports WHERE reporter_id = ?', [$id]);
        $u['listings'] = $db->fetchAll('SELECT id, title, slug, status FROM listings WHERE user_id = ? ORDER BY id DESC', [$id]);
        $u['audit'] = $db->fetchAll('SELECT action, created_at, ip_address FROM audit_logs WHERE actor_id = ? OR (target_type = \'user\' AND target_id = ?) ORDER BY id DESC LIMIT 30', [$id, $id]);
        return $u;
    }

    public function setStatus(User $staff, int $userId, string $status, string $reason): void
    {
        if (!$staff->can('users')) {
            throw new HttpException(403);
        }
        if (!in_array($status, ['active', 'restricted', 'suspended'], true)) {
            throw ValidationException::single('status', 'Invalid status.');
        }
        $target = $this->app->db()->fetch('SELECT id, role, status, email_verified_at FROM users WHERE id = ?', [$userId]);
        if (!$target || $target['status'] === 'deleted') {
            throw new HttpException(404);
        }
        if ($userId === $staff->id || ($target['role'] !== User::STUDENT && !$staff->isAdmin())) {
            throw new HttpException(403, 'Only a Super Admin can change staff accounts, and not their own.');
        }
        if ($status === 'active' && !$target['email_verified_at']) {
            $status = 'pending';
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if ($status !== 'active' && $status !== 'pending' && $reason === '') {
            throw ValidationException::single('reason', 'Give a reason (shown to the student).');
        }
        $this->app->db()->update('users', ['status' => $status, 'status_reason' => $reason ?: null], 'id = ?', [$userId]);
        if ($status === 'suspended') {
            $this->app->db()->run("UPDATE listings SET status = 'hidden', moderation_note = 'Owner account suspended' WHERE user_id = ? AND status IN ('published','pending')", [$userId]);
            $this->app->db()->run('UPDATE profiles SET discoverable = 0 WHERE user_id = ?', [$userId]);
        }
        $this->app->make(AuditLog::class)->log('user.status_' . $status, $staff->id, 'user', $userId, $reason ? ['reason' => $reason] : []);
        if ($status === 'restricted') {
            $this->app->make(Notifier::class)->notify($userId, 'account', 'Your account has been restricted: ' . $reason, '/account');
        }
    }

    /** Super Admin only: change role and moderator permissions. */
    public function setRole(User $admin, int $userId, string $role, array $permissions): void
    {
        if (!$admin->isAdmin()) {
            throw new HttpException(403);
        }
        if (!in_array($role, [User::STUDENT, User::MODERATOR, User::SUPER_ADMIN], true)) {
            throw ValidationException::single('role', 'Invalid role.');
        }
        if ($userId === $admin->id) {
            throw new HttpException(403, 'You cannot change your own role.');
        }
        $perms = $role === User::MODERATOR ? implode(',', array_values(array_intersect(array_keys(User::PERMISSIONS), $permissions))) : null;
        if ($this->app->db()->update('users', ['role' => $role, 'permissions' => $perms], "id = ? AND status <> 'deleted'", [$userId]) === 0
            && !$this->app->db()->fetchColumn('SELECT 1 FROM users WHERE id = ?', [$userId])) {
            throw new HttpException(404);
        }
        $this->app->make(AuditLog::class)->log('user.role_changed', $admin->id, 'user', $userId, ['role' => $role, 'permissions' => $perms]);
    }

    /** Super Admin: create a staff account (e.g. a moderator). Returns the id. */
    public function createStaff(User $admin, string $name, string $email, string $password, string $role, array $permissions): int
    {
        if (!$admin->isAdmin()) {
            throw new HttpException(403);
        }
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen(trim($name)) < 2) {
            throw ValidationException::single('email', 'Enter a name and a valid email.');
        }
        if ($p = Auth::passwordProblem($password, $email)) {
            throw ValidationException::single('password', $p);
        }
        if ($this->app->db()->fetchColumn('SELECT 1 FROM users WHERE email = ?', [$email])) {
            throw ValidationException::single('email', 'This email is already registered.');
        }
        $role = $role === User::SUPER_ADMIN ? User::SUPER_ADMIN : User::MODERATOR;
        $id = $this->app->db()->insert('users', ['name' => trim($name), 'email' => $email, 'password_hash' => Auth::hash($password), 'role' => $role, 'status' => 'active', 'email_verified_at' => gmdate('Y-m-d H:i:s'), 'age_confirmed' => 1,
            'permissions' => $role === User::MODERATOR ? implode(',', array_values(array_intersect(array_keys(User::PERMISSIONS), $permissions))) : null, 'password_changed_at' => gmdate('Y-m-d H:i:s')]);
        $this->app->make(AuditLog::class)->log('user.staff_created', $admin->id, 'user', $id, ['role' => $role]);
        return $id;
    }

    public function auditLogs(array $f, int $page): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['action'])) {
            $where[] = 'a.action LIKE ?';
            $params[] = str_replace(['%', '_'], ['\%', '\_'], mb_substr((string)$f['action'], 0, 60)) . '%';
        }
        if (!empty($f['actor'])) {
            $where[] = 'a.actor_id = ?';
            $params[] = (int)$f['actor'];
        }
        $w = implode(' AND ', $where);
        $db = $this->app->db();
        return [
            'total' => (int)$db->fetchColumn("SELECT COUNT(*) FROM audit_logs a WHERE $w", $params),
            'rows' => $db->fetchAll("SELECT a.*, u.name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id WHERE $w ORDER BY a.id DESC LIMIT 50 OFFSET " . ((max(1, $page) - 1) * 50), $params),
        ];
    }

    public function health(): array
    {
        $db = $this->app->db();
        $storage = $this->app->config->storagePath();
        $checks = [];
        $checks[] = ['PHP version', PHP_VERSION, PHP_VERSION_ID >= 80200];
        $checks[] = ['Database', (string)$db->fetchColumn('SELECT VERSION()'), true];
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'gd'] as $ext) {
            $checks[] = ["Extension $ext", extension_loaded($ext) ? 'loaded' : 'missing', extension_loaded($ext)];
        }
        foreach (['logs', 'sessions', 'uploads', 'private', 'mail'] as $d) {
            $p = $storage . '/' . $d;
            $checks[] = ["storage/$d writable", is_dir($p) && is_writable($p) ? 'ok' : 'not writable', is_dir($p) && is_writable($p)];
        }
        $free = @disk_free_space($storage);
        $checks[] = ['Free disk space', $free ? round($free / 1073741824, 1) . ' GB' : 'unknown', !$free || $free > 524288000];
        $mailer = $this->app->make(Mailer::class);
        $checks[] = ['Email (SMTP)', $mailer->isConfigured() ? 'configured' : 'not configured — OTP emails cannot be sent', $mailer->isConfigured()];
        $lastSent = $db->fetchColumn("SELECT MAX(sent_at) FROM mail_queue WHERE status = 'sent'");
        $lastErr = $db->fetchColumn("SELECT last_error FROM mail_queue WHERE last_error IS NOT NULL ORDER BY id DESC LIMIT 1");
        $checks[] = ['Last email delivered', $lastSent ? fmt_dt((string)$lastSent) : 'never', (bool)$lastSent || !$lastErr];
        if ($lastErr) {
            $checks[] = ['Last email error', (string)$lastErr, false];
        }
        $checks[] = ['HTTPS app URL', (string)$this->app->config->get('app.url'), str_starts_with((string)$this->app->config->get('app.url'), 'https://')];
        $checks[] = ['Debug mode off', $this->app->isDebug() ? 'ON' : 'off', !$this->app->isDebug()];
        $cron = $db->fetchAll('SELECT * FROM cron_runs ORDER BY script');
        foreach (['mail' => 10, 'cleanup' => 1500, 'deletions' => 1500] as $script => $maxMin) {
            $row = current(array_filter($cron, static fn($r) => $r['script'] === $script)) ?: null;
            $ok = $row && strtotime($row['last_run_at'] . ' UTC') > time() - $maxMin * 60 && $row['status'] === 'ok';
            $checks[] = ["Cron: $script", $row ? ago($row['last_run_at']) . ' — ' . $row['status'] . ($row['message'] ? ' (' . $row['message'] . ')' : '') : 'never ran', $ok];
        }
        return $checks;
    }
}
