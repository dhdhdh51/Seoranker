<?php
declare(strict_types=1);

namespace Flatmates\Security;

use Flatmates\Core\App;

/** Hardened sessions: strict mode, HttpOnly/SameSite/Secure cookie, idle + absolute expiry, ID rotation. */
final class Session
{
    public static function start(App $app, bool $https): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = $app->config->storagePath('sessions');
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        $idle = max(15, (int)$app->settings()->get('security.session_idle_minutes', 120)) * 60;
        ini_set('session.gc_maxlifetime', (string)max($idle, 7200));
        session_name((string)$app->config->get('session.name', 'SFFSESSID'));
        session_set_cookie_params(['lifetime' => 0, 'path' => (base_path() ?: '') . '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        session_cache_limiter('');
        session_start();
        $now = time();
        if (isset($_SESSION['_created']) && ($now - (int)$_SESSION['_created'] > 86400 * 14 || $now - (int)($_SESSION['_last'] ?? 0) > $idle)) {
            $had = isset($_SESSION['uid']);
            self::destroy();
            session_start();
            if ($had) {
                self::flash('warning', 'You were signed out after a period of inactivity.');
            }
        }
        $_SESSION['_created'] ??= $now;
        if ($now - (int)($_SESSION['_rot'] ?? 0) > 900) {
            session_regenerate_id(true);
            $_SESSION['_rot'] = $now;
        }
        $_SESSION['_last'] = $now;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_rot'] = $_SESSION['_created'] = time();
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (!headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        }
        session_destroy();
    }

    public static function flash(string $type, string $msg): void
    {
        $_SESSION['_flash'][$type][] = $msg;
    }

    public static function pullFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($f) ? $f : [];
    }
}
