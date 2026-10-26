<?php
declare(strict_types=1);

namespace Flatmates\Security;

use Flatmates\Core\App;
use Flatmates\Core\Database;
use Flatmates\Core\ValidationException;
use Flatmates\Services\AuditLog;
use Flatmates\Services\OtpService;

/** Registration, email OTP verification, login throttling, sessions and password reset. */
final class Auth
{
    public const MAX_FAILURES = 5;
    public const WINDOW_MIN = 15;

    private ?User $current = null;
    private bool $resolved = false;

    public function __construct(private App $app)
    {
    }

    private function db(): Database
    {
        return $this->app->db();
    }

    public static function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    public static function passwordProblem(string $p, string $email = ''): ?string
    {
        if (mb_strlen($p) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (mb_strlen($p) > 200) {
            return 'Password is too long.';
        }
        if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/[0-9\W_]/', $p)) {
            return 'Use letters plus at least one number or symbol.';
        }
        $local = strtolower((string)strtok($email, '@'));
        if ($local !== '' && strlen($local) >= 4 && str_contains(strtolower($p), $local)) {
            return 'Password must not contain your email name.';
        }
        return null;
    }

    public static function age(string $dob): ?int
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        return $d ? (int)$d->diff(new \DateTimeImmutable('today'))->y : null;
    }

    /** Create a pending student account and email a verification code. Returns user id. */
    public function register(array $in): int
    {
        $name = trim((string)($in['name'] ?? ''));
        $email = strtolower(trim((string)($in['email'] ?? '')));
        $password = (string)($in['password'] ?? '');
        $dob = (string)($in['date_of_birth'] ?? '');
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || preg_match('/[<>\x00-\x1F]/', $name)) {
            $errors['name'] = 'Enter your name (2–120 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($p = self::passwordProblem($password, $email)) {
            $errors['password'] = $p;
        } elseif ($password !== (string)($in['password_confirmation'] ?? '')) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }
        $min = (int)$this->app->settings()->get('rules.min_age', 18);
        $age = self::age($dob);
        if ($age === null || $age > 100) {
            $errors['date_of_birth'] = 'Enter your date of birth.';
        } elseif ($age < $min) {
            $errors['date_of_birth'] = "You must be at least $min years old to join.";
        }
        if (empty($in['age_confirmed']) || empty($in['terms'])) {
            $errors['terms'] = 'Please confirm your age and accept the terms and safety guidelines.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $existing = $this->db()->fetch('SELECT id, status FROM users WHERE email = ?', [$email]);
        if ($existing) {
            // Do not reveal whether the email is registered beyond the normal duplicate error.
            throw ValidationException::single('email', 'An account with this email already exists. Sign in or reset your password.');
        }
        $id = $this->db()->insert('users', ['name' => $name, 'email' => $email, 'password_hash' => self::hash($password), 'role' => User::STUDENT, 'status' => 'pending', 'date_of_birth' => $dob, 'age_confirmed' => 1]);
        $this->app->make(AuditLog::class)->log('auth.registered', $id, 'user', $id);
        $this->sendVerification($id);
        return $id;
    }

    public function sendVerification(int $userId): string
    {
        $u = $this->db()->fetch('SELECT id, email, name FROM users WHERE id = ?', [$userId]);
        return $this->app->make(OtpService::class)->issue($userId, 'email_verify', $u['email'], 'Verify your email', "Hi {$u['name']}, welcome to " . brand() . '! Enter this code to verify your email address.');
    }

    public function verifyEmail(int $userId, string $code): void
    {
        $this->app->make(OtpService::class)->verify($userId, 'email_verify', $code);
        $this->db()->run("UPDATE users SET email_verified_at = UTC_TIMESTAMP(), status = IF(status = 'pending', 'active', status) WHERE id = ?", [$userId]);
        $this->app->make(AuditLog::class)->log('auth.email_verified', $userId, 'user', $userId);
        $this->resolved = false;
    }

    public function throttled(string $email, string $ip): bool
    {
        $h = hash('sha256', strtolower(trim($email)));
        $byEmail = (int)$this->db()->fetchColumn('SELECT COUNT(*) FROM login_attempts WHERE email_hash = ? AND success = 0 AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE', [$h, self::WINDOW_MIN]);
        $byIp = (int)$this->db()->fetchColumn('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE', [$ip, self::WINDOW_MIN]);
        return $byEmail >= self::MAX_FAILURES || $byIp >= self::MAX_FAILURES * 5;
    }

    /** @return 'ok'|'invalid'|'throttled'|'suspended'|'maintenance' */
    public function attempt(string $email, string $password, string $ip, bool $startSession = true): string
    {
        $email = strtolower(trim($email));
        if ($this->throttled($email, $ip)) {
            $this->app->make(AuditLog::class)->log('auth.login_throttled', null, null, null, ['email_hash' => substr(hash('sha256', $email), 0, 12)]);
            return 'throttled';
        }
        $row = $this->db()->fetch('SELECT * FROM users WHERE email = ?', [$email]);
        $valid = password_verify($password, $row['password_hash'] ?? '$2y$10$usesomesillystringforsaltOVk1Q6a8jJ2sQ4m1y2lJ8m3kWqL7u') && $row !== null;
        $blocked = $valid && in_array($row['status'], ['suspended', 'deleted'], true);
        $this->db()->insert('login_attempts', ['email_hash' => hash('sha256', $email), 'ip_address' => $ip, 'success' => ($valid && !$blocked) ? 1 : 0]);
        if (!$valid) {
            return 'invalid';
        }
        if ($blocked) {
            return 'suspended';
        }
        if ($this->app->settings()->bool('system.maintenance') && $row['role'] === User::STUDENT) {
            return 'maintenance';
        }
        if (password_needs_rehash($row['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            $this->db()->update('users', ['password_hash' => self::hash($password)], 'id = ?', [$row['id']]);
        }
        $this->db()->update('users', ['last_login_at' => gmdate('Y-m-d H:i:s'), 'last_login_ip' => $ip], 'id = ?', [$row['id']]);
        if ($startSession) {
            $this->startSession((int)$row['id']);
        }
        $this->app->make(AuditLog::class)->log('auth.login', (int)$row['id'], 'user', (int)$row['id']);
        return 'ok';
    }

    public function startSession(int $userId): void
    {
        $row = $this->db()->fetch('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        Session::regenerate();
        Csrf::rotate();
        $_SESSION['uid'] = $userId;
        $_SESSION['pwm'] = substr(hash('sha256', (string)$row['password_hash']), 0, 16);
        $this->resolved = false;
    }

    /** Current user; re-checks account status and password marker on every request. */
    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->current;
        }
        $this->resolved = true;
        $this->current = null;
        $id = $_SESSION['uid'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $row = $this->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$row || in_array($row['status'], ['suspended', 'deleted'], true) || ($_SESSION['pwm'] ?? '') !== substr(hash('sha256', (string)$row['password_hash']), 0, 16)) {
            Session::destroy();
            return null;
        }
        if ($this->app->settings()->bool('system.maintenance') && $row['role'] === User::STUDENT) {
            Session::destroy();
            return null;
        }
        if (!$row['last_seen_at'] || strtotime($row['last_seen_at'] . ' UTC') < time() - 300) {
            $this->db()->run('UPDATE users SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
        }
        return $this->current = User::fromRow($row);
    }

    public function actingAs(?User $u): void
    {
        $this->current = $u;
        $this->resolved = true;
    }

    public function logout(): void
    {
        if ($u = $this->user()) {
            $this->app->make(AuditLog::class)->log('auth.logout', $u->id);
        }
        Session::destroy();
        $this->current = null;
    }

    /** Email a reset code. Silent for unknown emails (no account enumeration). */
    public function requestReset(string $email): ?string
    {
        $row = $this->db()->fetch("SELECT id, name, email FROM users WHERE email = ? AND status IN ('pending','active','restricted')", [strtolower(trim($email))]);
        if (!$row) {
            return null;
        }
        try {
            return $this->app->make(OtpService::class)->issue((int)$row['id'], 'password_reset', $row['email'], 'Reset your password', "Hi {$row['name']}, use this code to choose a new password.");
        } catch (ValidationException) {
            return null; // cooldown: stay silent
        }
    }

    public function resetPassword(string $email, string $code, string $password): void
    {
        $row = $this->db()->fetch('SELECT id, email FROM users WHERE email = ?', [strtolower(trim($email))]);
        if (!$row) {
            throw ValidationException::single('code', 'That code is not correct.');
        }
        if ($p = self::passwordProblem($password, $row['email'])) {
            throw ValidationException::single('password', $p);
        }
        $this->app->make(OtpService::class)->verify((int)$row['id'], 'password_reset', $code);
        $this->db()->run("UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(), email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()), status = IF(status = 'pending', 'active', status) WHERE id = ?", [self::hash($password), $row['id']]);
        $this->app->make(AuditLog::class)->log('auth.password_reset', (int)$row['id'], 'user', (int)$row['id']);
    }
}
