<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;

/**
 * Staff single sign-on FROM the NoidaRankers platform (seo.bharatseo.site → /sso?token=…).
 * Token: base64url(JSON payload) . "." . base64url(HMAC-SHA256(payload, shared secret)),
 * valid 60 s, audience = this host, single use (nonce stored). Only an existing, active
 * moderator / Super Admin account with the same email can sign in — students never can,
 * and no account is created automatically.
 */
final class SsoReceiver
{
    public function __construct(private App $app)
    {
    }

    public function isEnabled(): bool
    {
        return strlen((string)$this->app->settings()->get('sso.secret')) >= 32;
    }

    public static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function sign(array $payload, string $secret): string
    {
        $body = self::b64((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $body . '.' . self::b64(hash_hmac('sha256', $body, $secret, true));
    }

    /** @return int|null flatmates user id to sign in */
    public function consume(string $token): ?int
    {
        if (!$this->isEnabled() || strlen($token) > 4000) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        $secret = (string)$this->app->settings()->get('sso.secret');
        if (!hash_equals(self::b64(hash_hmac('sha256', $parts[0], $secret, true)), $parts[1])) {
            return null;
        }
        $p = json_decode((string)base64_decode(strtr($parts[0], '-_', '+/')), true);
        $now = time();
        $host = (string)parse_url((string)$this->app->config->get('app.url'), PHP_URL_HOST);
        if (!is_array($p) || ($p['aud'] ?? '') !== $host || (int)($p['exp'] ?? 0) < $now || (int)($p['iat'] ?? 0) > $now + 30 || !preg_match('/^[a-f0-9]{32}$/', (string)($p['nonce'] ?? ''))) {
            return null;
        }
        $db = $this->app->db();
        $db->run('DELETE FROM sso_nonces WHERE expires_at < UTC_TIMESTAMP()');
        try {
            $db->insert('sso_nonces', ['nonce' => $p['nonce'], 'expires_at' => gmdate('Y-m-d H:i:s', (int)$p['exp'] + 60)]);
        } catch (\PDOException $e) {
            if (\Flatmates\Core\Database::isDuplicateKey($e)) {
                return null; // replay
            }
            throw $e;
        }
        $user = $db->fetch("SELECT id FROM users WHERE email = ? AND role IN ('moderator','super_admin') AND status = 'active'", [strtolower((string)($p['email'] ?? ''))]);
        $this->app->make(AuditLog::class)->log($user ? 'auth.sso_login' : 'auth.sso_denied', $user ? (int)$user['id'] : null, null, null, ['from' => (string)($p['iss'] ?? '')]);
        return $user ? (int)$user['id'] : null;
    }
}
