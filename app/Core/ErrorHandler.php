<?php
declare(strict_types=1);

namespace BharatSeo\Core;

/**
 * Converts PHP errors to exceptions (debug) or logs them (production) and
 * renders safe error pages without stack traces or credentials.
 */
final class ErrorHandler
{
    private static ?App $app = null;

    public static function register(App $app): void
    {
        self::$app = $app;
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false; // suppressed with @
            }
            if (self::$app !== null && self::$app->isDebug()) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
            self::$app?->logger()->warning("PHP: $message", ['file' => basename($file), 'line' => $line]);
            return true;
        });

        set_exception_handler(static function (\Throwable $e): void {
            self::report($e);
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, 'Error: ' . (self::$app?->isDebug() ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : 'An unexpected error occurred. See storage/logs.') . "\n");
                exit(1);
            }
            self::renderErrorPage(500, 'An unexpected error occurred. The incident has been logged.');
        });
    }

    public static function report(\Throwable $e): void
    {
        try {
            self::$app?->logger()->error(get_class($e) . ': ' . $e->getMessage(), [
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
                'trace' => array_slice(array_map(
                    static fn(array $f) => (isset($f['class']) ? $f['class'] . '::' : '') . ($f['function'] ?? '') . (isset($f['line']) ? ':' . $f['line'] : ''),
                    $e->getTrace()
                ), 0, 12),
            ]);
        } catch (\Throwable) {
            // never fail while reporting
        }
    }

    public static function renderErrorPage(int $status, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
        }
        $file = APP_ROOT . '/views/errors/error.php';
        $title = $status === 404 ? 'Page not found' : ($status === 403 ? 'Access denied' : 'Something went wrong');
        if (is_file($file)) {
            $status_code = $status;
            include $file;
            return;
        }
        echo '<!doctype html><title>' . htmlspecialchars($title) . '</title><h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p>';
    }
}
