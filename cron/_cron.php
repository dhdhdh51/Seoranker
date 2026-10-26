<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

function flatmates_cli_cron(string $task): never
{
    try {
        $app = \Flatmates\Core\App::boot();
    } catch (\Flatmates\Core\NotInstalledException) {
        fwrite(STDERR, "Not installed\n");
        exit(1);
    }
    $r = $app->make(\Flatmates\Services\CronTasks::class)->run($task);
    fwrite(STDOUT, sprintf("[%s] %s: %s %s\n", gmdate('c'), $task, $r['status'], $r['message']));
    exit($r['status'] === 'failed' ? 1 : 0);
}
