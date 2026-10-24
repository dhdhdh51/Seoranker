<?php
declare(strict_types=1);

namespace BharatSeo\Security;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Services\AuditLogger;
use BharatSeo\Services\MailerService;

/**
 * Authentication: login with throttling, logout, password reset tokens.
 */
final class Auth
{
    public const MAX_EMAIL_FAILURES = 5;
    public const MAX_IP_FAILURES = 25;
    public const THROTTLE_WINDOW_MINUTES = 15;
    public const RESET_TTL_MINUTES = 60;
    public const MIN_PASSWORD_LENGTH = 10;

    private Database $db;
    private ?User $current = null;
    private bool $resolved = false;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($password, $algo);
    }

    /** @return string[] list of problems; empty when acceptable */
    public static function passwordProblems(string $password, string $email = ''): array
    {
        $problems = [];
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $problems[] = 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
        }
        if (mb_strlen($password) > 200) {
            $problems[] = 'Password is too long.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9\W_]/', $password)) {
            $problems[] = 'Password must contain letters and at least one number or symbol.';
        }
        if ($email !== '' && stripos($password, strtok($email, '@') ?: "\0") !== false) {
            $problems[] = 'Password must not contain your email name.';
        }
        return $problems;
    }

    public function isThrottled(string $email, string $ip): bool
    {
        $emailHash = hash('sha256', strtolower(trim($email)));
        $byEmail = (int)$this->db->fetchColumn(
            'SELECT COUNT(*) FROM login_attempts WHERE email_hash = ? AND success = 0 AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE',
            [$emailHash, self::THROTTLE_WINDOW_MINUTES]
        );
        $byIp = (int)$this->db->fetchColumn(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE',
            [$ip, self::THROTTLE_WINDOW_MINUTES]
        );
        return $byEmail >= self::MAX_EMAIL_FAILURES || $byIp >= self::MAX_IP_FAILURES;
    }

    /**
     * Attempt a login. Returns 'ok', 'invalid', 'throttled' or 'disabled' (disabled is reported to
     * the user as 'invalid' to avoid account enumeration).
     */
    public function attempt(string $email, string $password, string $ip, bool $startSession = true): string
    {
        $email = strtolower(trim($email));
        if ($this->isThrottled($email, $ip)) {
            $this->audit()->log('auth.login_throttled', null, null, ['email_hash' => substr(hash('sha256', $email), 0, 16)], null, $ip);
            return 'throttled';
        }
        $row = $this->db->fetch('SELECT * FROM users WHERE email = ?', [$email]);
        // Constant-time-ish behaviour for unknown users.
        $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringforsaltOVk1Q6a8jJ2sQ4m1y2lJ8m3kWqL7u';
        $valid = password_verify($password, $hash) && $row !== null;
        $success = $valid && $row['status'] === 'active';
        $this->db->insert('login_attempts', [
            'email_hash' => hash('sha256', $email),
            'ip_address' => $ip,
            'success' => $success ? 1 : 0,
        ]);
        if (!$valid) {
            return 'invalid';
        }
        if ($row['status'] !== 'active') {
            $this->audit()->log('auth.login_disabled_account', (int)$row['id'], null, [], null, $ip);
            return 'disabled';
        }
        if (password_needs_rehash($row['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            $this->db->update('users', ['password_hash' => self::hashPassword($password)], 'id = ?', [$row['id']]);
        }
        $this->db->update('users', ['last_login_at' => gmdate('Y-m-d H:i:s'), 'last_login_ip' => $ip], 'id = ?', [$row['id']]);
        if ($startSession) {
            Session::regenerate();
            Csrf::rotate();
            $_SESSION['user_id'] = (int)$row['id'];
            $_SESSION['pwd_marker'] = substr(hash('sha256', (string)$row['password_hash']), 0, 16);
        }
        $this->current = User::fromRow($row);
        $this->resolved = true;
        $this->audit()->log('auth.login', (int)$row['id'], null, [], null, $ip);
        return 'ok';
    }

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->current;
        }
        $this->resolved = true;
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM users WHERE id = ?', [$id]);
        // Invalidate sessions of disabled users or after a password change.
        if ($row === null || $row['status'] !== 'active' || ($_SESSION['pwd_marker'] ?? '') !== substr(hash('sha256', (string)$row['password_hash']), 0, 16)) {
            Session::destroy();
            return null;
        }
        return $this->current = User::fromRow($row);
    }

    /** For CLI/tests: act as a given user without a session. */
    public function actingAs(?User $user): void
    {
        $this->current = $user;
        $this->resolved = true;
    }

    public function logout(?string $ip = null): void
    {
        $u = $this->user();
        if ($u) {
            $this->audit()->log('auth.logout', $u->id, null, [], null, $ip);
        }
        Session::destroy();
        $this->current = null;
    }

    /**
     * Create a reset token and email it. Always behaves the same for unknown emails.
     * Returns the plaintext token only for internal use (tests / admin-generated link).
     */
    public function requestPasswordReset(string $email, string $ip, bool $sendEmail = true): ?string
    {
        $email = strtolower(trim($email));
        $row = $this->db->fetch("SELECT id, name, email FROM users WHERE email = ? AND status = 'active'", [$email]);
        if ($row === null) {
            return null;
        }
        // Limit to 3 active reset requests per user per hour.
        $recent = (int)$this->db->fetchColumn('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR', [$row['id']]);
        if ($recent >= 3) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $this->db->insert('password_resets', [
            'user_id' => (int)$row['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::RESET_TTL_MINUTES * 60),
            'ip_address' => $ip,
        ]);
        $this->audit()->log('auth.password_reset_requested', (int)$row['id'], null, [], null, $ip);
        if ($sendEmail) {
            $link = absolute_url('/reset-password?token=' . $token);
            $body = "Hello {$row['name']},\n\nA password reset was requested for your " . brand() . " account.\n"
                . "Open this link within " . self::RESET_TTL_MINUTES . " minutes to choose a new password:\n\n$link\n\n"
                . "If you did not request this, you can ignore this email.\n\n— " . brand();
            try {
                $this->app->make(MailerService::class)->sendSystemMail($row['email'], 'Reset your ' . brand() . ' password', $body);
            } catch (\Throwable $e) {
                $this->app->logger()->warning('Password reset email failed', ['error' => $e->getMessage()]);
            }
        }
        return $token;
    }

    public function findValidReset(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return $this->db->fetch(
            'SELECT pr.*, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > UTC_TIMESTAMP() AND u.status = \'active\'',
            [hash('sha256', $token)]
        );
    }

    /** @return string[] problems (empty on success) */
    public function completePasswordReset(string $token, string $password, string $ip): array
    {
        $reset = $this->findValidReset($token);
        if ($reset === null) {
            return ['This reset link is invalid or has expired.'];
        }
        $problems = self::passwordProblems($password, (string)$reset['email']);
        if ($problems) {
            return $problems;
        }
        $this->db->transaction(function (Database $db) use ($reset, $password): void {
            $db->update('users', ['password_hash' => self::hashPassword($password), 'password_changed_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$reset['user_id']]);
            // Invalidate every outstanding token for this user.
            $db->run('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$reset['user_id']]);
        });
        $this->audit()->log('auth.password_reset_completed', (int)$reset['user_id'], null, [], null, $ip);
        return [];
    }

    private function audit(): AuditLogger
    {
        return $this->app->make(AuditLogger::class);
    }
}
