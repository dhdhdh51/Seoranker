<?php
// Router for PHP's built-in development server (tests / local preview only):
//   php -S 127.0.0.1:8099 -t public tests/dev-router.php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Mirror public/.htaccess: never serve hidden files.
if (preg_match('#(^|/)\.#', $path) && $path !== '/.well-known/assetlinks.json') {
    http_response_code(403);
    exit('Forbidden');
}
$file = realpath(__DIR__ . '/../public' . $path);
$public = realpath(__DIR__ . '/../public');
if ($path !== '/' && $file !== false && str_starts_with($file, $public) && is_file($file)) {
    if (str_ends_with($file, '.php')) {
        $_SERVER['SCRIPT_NAME'] = $path;
        require $file;
        return true;
    }
    return false; // let the built-in server serve static assets
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../public/index.php';
