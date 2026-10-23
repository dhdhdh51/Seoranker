<?php
declare(strict_types=1);

/**
 * Application bootstrap shared by the web front controller, cron scripts,
 * CLI tools and tests.
 */

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit("Bharat SEO requires PHP 8.2 or newer.\n");
}

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

spl_autoload_register(static function (string $class): void {
    $prefix = 'BharatSeo\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = APP_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/app/Support/helpers.php';

use BharatSeo\Core\App;

/**
 * Boot the application. $requireConfig=false is used by the installer.
 */
function bharatseo_boot(bool $requireConfig = true, ?array $configOverride = null): App
{
    return App::boot($requireConfig, $configOverride);
}
