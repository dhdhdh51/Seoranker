<?php
declare(strict_types=1);

namespace Flatmates\Security;

/** Synchronizer-token CSRF protection bound to the session. */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function validate(?string $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? null;
        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    public static function rotate(): void
    {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
}
