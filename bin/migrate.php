<?php
declare(strict_types=1);

/** Apply pending database migrations: php bin/migrate.php  (back up first — see UPGRADE.md) */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

$app = bharatseo_boot();
$m = $app->make(\BharatSeo\Services\Migrator::class);
$done = $m->migrate(null);
fwrite(STDOUT, $done ? 'Applied: ' . implode(', ', $done) . "\n" : "Database is up to date.\n");
foreach ($m->status() as $v => $s) {
    fwrite(STDOUT, sprintf("  %-30s %s\n", $v, $s['applied_at'] ?? 'PENDING'));
}
