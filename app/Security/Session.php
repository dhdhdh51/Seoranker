<?php
declare(strict_types=1);

namespace BharatSeo\Security;

use BharatSeo\Core\App;

/**
 * Hardened PHP session handling: strict mode, HttpOnly/SameSite/Secure cookies,
 * private save path, idle + absolute expiry and periodic ID regeneration.
 */
final class Session
{
    public static function start(App $app, bool $isHttps): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $savePath = $app->config->storagePath('sessions');
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0700, true);
        }
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }
        $secureCfg = $app->config->get('session.secure_cookie', 'auto');
        $secure = $secureCfg === 'auto' ? $isHttps : (bool)$secureCfg;

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string)(self::absoluteSeconds($app)));

        session_name((string)$app->config->get('session.name', 'BSEOSESSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => self::cookiePath($app),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $idle = self::idleSeconds($app);
        $absolute = self::absoluteSeconds($app);
        if (isset($_SESSION['_created']) && ($now - (int)$_SESSION['_created'] > $absolute || $now - (int)($_SESSION['_last'] ?? 0) > $idle)) {
            $expiredUser = isset($_SESSION['user_id']);
            self::destroy();
            session_start();
            if ($expiredUser) {
                $_SESSION['_flash']['warning'][] = 'Your session expired. Please sign in again.';
            }
        }
        $_SESSION['_created'] ??= $now;
        if ($now - (int)($_SESSION['_regenerated'] ?? 0) > 900) {
            session_regenerate_id(true);
            $_SESSION['_regenerated'] = $now;
        }
        $_SESSION['_last'] = $now;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_regenerated'] = time();
            $_SESSION['_created'] = time();
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        }
        session_destroy();
    }

    public static function idleSeconds(App $app): int
    {
        $minutes = (int)$app->config->get('session.idle_minutes', 60);
        try {
            $minutes = (int)$app->settings()->get('security.session_idle_minutes', $minutes);
        } catch (\Throwable) {
        }
        return max(5, $minutes) * 60;
    }

    public static function absoluteSeconds(App $app): int
    {
        return max(1, (int)$app->config->get('session.absolute_hours', 12)) * 3600;
    }

    private static function cookiePath(App $app): string
    {
        $url = (string)$app->config->get('app.url', '');
        $path = $url !== '' ? (parse_url($url, PHP_URL_PATH) ?: '/') : '/';
        return rtrim($path, '/') . '/';
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type][] = $message;
    }

    /** @return array<string, string[]> */
    public static function pullFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($f) ? $f : [];
    }
}
