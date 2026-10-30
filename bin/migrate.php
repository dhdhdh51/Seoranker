<?php
declare(strict_types=1);

/** Apply pending database migrations:  php bin/migrate.php [--status] */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

$app = \Flatmates\Core\App::boot();
$m = $app->make(\Flatmates\Services\Migrator::class);
if (in_array('--status', $argv, true)) {
    foreach ($m->status() as $v => $at) {
        fwrite(STDOUT, str_pad($v, 40) . ($at ?? 'PENDING') . "\n");
    }
    exit(0);
}
$done = $m->migrate();
fwrite(STDOUT, $done ? 'Applied: ' . implode(', ', $done) . "\n" : "Database is up to date.\n");
