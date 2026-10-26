<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\ValidationException;
use Flatmates\Security\RateLimiter;

/**
 * 6-digit one-time codes. Only an HMAC of the code is stored; codes expire after 10 minutes,
 * allow 5 attempts, and resends are rate-limited (60 s cooldown, 5 per hour). Codes are never logged.
 */
final class OtpService
{
    public const TTL = 600;
    public const MAX_ATTEMPTS = 5;
    public const COOLDOWN = 60;
    public const PER_HOUR = 5;

    public function __construct(private App $app)
    {
    }

    private function hash(string $code, int $userId, string $purpose): string
    {
        return hash_hmac('sha256', "$userId|$purpose|$code", (string)$this->app->config->get('app.key'));
    }

    /** Create and email a code. Returns the code only so tests can use it. */
    public function issue(int $userId, string $purpose, string $email, string $subject, string $intro): string
    {
        $db = $this->app->db();
        $last = $db->fetchColumn('SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) FROM otp_codes WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);
        if ($last !== null && (int)$last < self::COOLDOWN) {
            throw ValidationException::single('otp', 'Please wait ' . (self::COOLDOWN - (int)$last) . ' seconds before requesting another code.');
        }
        if (!$this->app->make(RateLimiter::class)->hit("otp:$purpose:$userId", self::PER_HOUR, 3600)) {
            throw ValidationException::single('otp', 'Too many codes requested. Please try again in an hour.');
        }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->run('UPDATE otp_codes SET consumed_at = UTC_TIMESTAMP() WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL', [$userId, $purpose]);
        $db->insert('otp_codes', [
            'user_id' => $userId, 'purpose' => $purpose, 'target_email' => $email,
            'code_hash' => $this->hash($code, $userId, $purpose),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL),
        ]);
        $this->app->make(Mailer::class)->send($email, $subject, "$intro\n\nYour code is: $code\n\nIt expires in " . intdiv(self::TTL, 60) . " minutes. If you did not request it, you can ignore this email. Never share this code with anyone.");
        return $code;
    }

    /** Returns the matched OTP row (with target_email) or throws. */
    public function verify(int $userId, string $purpose, string $code): array
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        $db = $this->app->db();
        $row = $db->fetch('SELECT * FROM otp_codes WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1', [$userId, $purpose]);
        if (!$row || strtotime($row['expires_at'] . ' UTC') < time()) {
            throw ValidationException::single('code', 'This code has expired. Request a new one.');
        }
        if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) {
            throw ValidationException::single('code', 'Too many wrong attempts. Request a new code.');
        }
        if (strlen($code) !== 6 || !hash_equals($row['code_hash'], $this->hash($code, $userId, $purpose))) {
            $db->run('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            throw ValidationException::single('code', 'That code is not correct.');
        }
        $db->run('UPDATE otp_codes SET consumed_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
        return $row;
    }
}
