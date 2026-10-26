<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Security\User;

/**
 * Single sign-on to the company website admin (e.g. noidarankers.in/admin).
 * Issues a short-lived (60 s), single-use, HMAC-SHA256 signed token that the
 * website verifies with the same shared secret. No passwords are shared.
 */
final class SsoService
{
    public const TTL = 60;

    public function __construct(private App $app)
    {
    }

    public function isEnabled(): bool
    {
        $s = $this->app->settings();
        return $s->bool('sso.website_enabled') && $s->has('sso.website_url') && strlen((string)$s->get('sso.secret')) >= 32;
    }

    public function allowed(User $user): bool
    {
        if (!$this->isEnabled() || $user->status !== 'active') {
            return false;
        }
        return match ((string)$this->app->settings()->get('sso.roles', 'staff')) {
            'super_admin' => $user->isSuperAdmin(),
            default => $user->isStaff(),
        };
    }

    public function websiteUrl(): string
    {
        return rtrim((string)$this->app->settings()->get('sso.website_url'), '/');
    }

    public static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function sign(array $payload, string $secret): string
    {
        $body = self::b64((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $body . '.' . self::b64(hash_hmac('sha256', $body, $secret, true));
    }

    /** Reference verifier (mirrors the website implementation; used by tests). */
    public static function verify(string $token, string $secret, string $audience, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        if (!hash_equals(self::b64(hash_hmac('sha256', $parts[0], $secret, true)), $parts[1])) {
            return null;
        }
        $p = json_decode((string)base64_decode(strtr($parts[0], '-_', '+/')), true);
        $now ??= time();
        if (!is_array($p) || ($p['aud'] ?? '') !== $audience || (int)($p['exp'] ?? 0) < $now || (int)($p['iat'] ?? 0) > $now + 30) {
            return null;
        }
        return $p;
    }

    public function tokenFor(User $user): string
    {
        $now = time();
        $payload = [
            'iss' => (string)parse_url((string)$this->app->config->get('app.url'), PHP_URL_HOST),
            'aud' => (string)parse_url($this->websiteUrl(), PHP_URL_HOST),
            'sub' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
            'iat' => $now,
            'exp' => $now + self::TTL,
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $this->app->make(AuditLogger::class)->log('sso.website_login', $user->id, null, ['website' => $payload['aud']]);
        return self::sign($payload, (string)$this->app->settings()->get('sso.secret'));
    }

    /** Staff sign-in to the Student Flatmate Finder admin (separate app, separate secret). */
    public function flatmatesEnabled(): bool
    {
        $s = $this->app->settings();
        return $s->has('sso.flatmates_url') && strlen((string)$s->get('sso.flatmates_secret')) >= 32;
    }

    public function flatmatesAllowed(User $user): bool
    {
        return $this->flatmatesEnabled() && $user->status === 'active' && $user->isStaff();
    }

    public function flatmatesUrl(): string
    {
        return rtrim((string)$this->app->settings()->get('sso.flatmates_url'), '/');
    }

    public function flatmatesTokenFor(User $user): string
    {
        $now = time();
        $payload = [
            'iss' => (string)parse_url((string)$this->app->config->get('app.url'), PHP_URL_HOST),
            'aud' => (string)parse_url($this->flatmatesUrl(), PHP_URL_HOST),
            'sub' => $user->id, 'email' => $user->email, 'name' => $user->name, 'role' => $user->role,
            'iat' => $now, 'exp' => $now + self::TTL, 'nonce' => bin2hex(random_bytes(16)),
        ];
        $this->app->make(AuditLogger::class)->log('sso.flatmates_login', $user->id, null, ['app' => $payload['aud']]);
        return self::sign($payload, (string)$this->app->settings()->get('sso.flatmates_secret'));
    }
}
