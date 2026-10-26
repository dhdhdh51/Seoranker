<?php
declare(strict_types=1);

/**
 * CLI installer:
 *   FLATMATES_DB_PASS=... FLATMATES_ADMIN_PASSWORD=... php bin/install.php --db-name=flatmates --db-user=flatmates \
 *     --app-url=https://flatmates.example.com --admin-name="Admin" --admin-email=admin@example.com [--db-host=localhost] [--config=/path]
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

$o = getopt('', ['db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-pass:', 'app-url:', 'admin-name:', 'admin-email:', 'admin-password:', 'timezone:', 'config:', 'storage:', 'no-seed']);
try {
    $r = \Flatmates\Services\Installer::install([
        'db_host' => (string)($o['db-host'] ?? 'localhost'), 'db_port' => (int)($o['db-port'] ?? 3306),
        'db_name' => (string)($o['db-name'] ?? ''), 'db_user' => (string)($o['db-user'] ?? ''),
        'db_pass' => (string)($o['db-pass'] ?? (getenv('FLATMATES_DB_PASS') ?: '')),
        'app_url' => (string)($o['app-url'] ?? ''), 'admin_name' => (string)($o['admin-name'] ?? ''), 'admin_email' => (string)($o['admin-email'] ?? ''),
        'admin_password' => (string)($o['admin-password'] ?? (getenv('FLATMATES_ADMIN_PASSWORD') ?: '')),
        'timezone' => (string)($o['timezone'] ?? 'Asia/Kolkata'), 'seed_locations' => !isset($o['no-seed']),
    ], isset($o['config']) ? (string)$o['config'] : null, isset($o['storage']) ? (string)$o['storage'] : null);
    fwrite(STDOUT, "Installed. Config written to {$r['config_path']}\nNext: delete public/install.php and add the cron jobs (see docs/CRON.md).\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
    exit(1);
}
