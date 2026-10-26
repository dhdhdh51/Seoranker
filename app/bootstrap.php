<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit("Student Flatmate Finder requires PHP 8.2 or newer.\n");
}

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Flatmates\\')) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 10)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Optional Composer dependencies (e.g. PHPMailer) — upload vendor/ if Composer is unavailable on the host.
if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';
}

require APP_ROOT . '/app/Support/helpers.php';
