<?php
declare(strict_types=1);

namespace Flatmates\Core;

/** Logs errors (with secret redaction) and shows safe error pages without stack traces. */
final class ErrorHandler
{
    private static ?App $app = null;

    public static function register(App $app): void
    {
        self::$app = $app;
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            if (self::$app?->isDebug()) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
            self::$app?->logger()->warning('PHP: ' . $message, ['file' => basename($file), 'line' => $line]);
            return true;
        });
        set_exception_handler(static function (\Throwable $e): void {
            self::report($e);
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, 'Error: ' . (self::$app?->isDebug() ? $e->getMessage() : 'see storage/logs') . "\n");
                exit(1);
            }
            if (!headers_sent()) {
                http_response_code(500);
            }
            echo '<!doctype html><meta charset="utf-8"><title>Error</title><p style="font-family:sans-serif;padding:2rem">Something went wrong. Please try again later.</p>';
        });
    }

    public static function report(\Throwable $e): void
    {
        try {
            self::$app?->logger()->error(get_class($e) . ': ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]);
        } catch (\Throwable) {
        }
    }
}
