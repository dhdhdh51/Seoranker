<?php
declare(strict_types=1);

/**
 * php bin/backup.php                 -> storage/backups/bharatseo-YYYYmmdd-HHMMSS.sql.gz
 * php bin/backup.php --restore=FILE  -> restores FILE (DESTROYS current data; take a backup first)
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

$app = bharatseo_boot();
$svc = $app->make(\BharatSeo\Services\BackupService::class);
$opts = getopt('', ['restore:', 'yes']);
if (isset($opts['restore'])) {
    if (!isset($opts['yes'])) {
        fwrite(STDERR, "Restoring overwrites all Bharat SEO tables. Re-run with --yes to confirm.\n");
        exit(2);
    }
    $n = $svc->restore((string)$opts['restore']);
    fwrite(STDOUT, "Restored $n statements from {$opts['restore']}\n");
    exit(0);
}
$file = $svc->backup();
fwrite(STDOUT, "Backup written: $file (" . filesize($file) . " bytes)\n");
