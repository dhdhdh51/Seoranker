<?php
declare(strict_types=1);

/**
 * Non-interactive CLI installer (alternative to public/install.php).
 *
 *   php bin/install.php --db-host=localhost --db-name=bseo --db-user=bseo --db-pass=SECRET \
 *       --app-url=https://seo.example.com --admin-name="Asha Admin" --admin-email=admin@example.com \
 *       [--admin-password=...|env BSEO_ADMIN_PASSWORD] [--timezone=Asia/Kolkata] [--config=/path/config.php]
 *
 * Prefer passing passwords through the environment (BSEO_DB_PASS, BSEO_ADMIN_PASSWORD) so they do not
 * appear in shell history or the process list.
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use BharatSeo\Services\Installer;

$opts = getopt('', ['db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-pass:', 'db-socket:', 'app-url:', 'timezone:', 'admin-name:', 'admin-email:', 'admin-password:', 'config:', 'storage:', 'no-pretty-urls']);
bharatseo_boot(false);
try {
    $r = Installer::install([
        'db_host' => (string)($opts['db-host'] ?? 'localhost'),
        'db_port' => (int)($opts['db-port'] ?? 3306),
        'db_name' => (string)($opts['db-name'] ?? ''),
        'db_user' => (string)($opts['db-user'] ?? ''),
        'db_pass' => (string)($opts['db-pass'] ?? getenv('BSEO_DB_PASS') ?: ''),
        'db_socket' => (string)($opts['db-socket'] ?? ''),
        'app_url' => (string)($opts['app-url'] ?? ''),
        'timezone' => (string)($opts['timezone'] ?? 'Asia/Kolkata'),
        'admin_name' => (string)($opts['admin-name'] ?? ''),
        'admin_email' => (string)($opts['admin-email'] ?? ''),
        'admin_password' => (string)($opts['admin-password'] ?? getenv('BSEO_ADMIN_PASSWORD') ?: ''),
        'pretty_urls' => !isset($opts['no-pretty-urls']),
    ], isset($opts['config']) ? (string)$opts['config'] : null, isset($opts['storage']) ? (string)$opts['storage'] : null);
    fwrite(STDOUT, "Installed. Config: {$r['config_path']}\nDelete public/install.php and add the cron jobs from CRON_SETUP.md.\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
    exit(1);
}
