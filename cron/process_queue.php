<?php
declare(strict_types=1);

/**
 * Process due jobs from the MySQL queue (evaluations, imports, searches, checks, drafts). Run every 1-5 minutes.
 * Usage (cPanel/aPanel): /usr/local/bin/php /home/USER/bharatseo/cron/process_queue.php
 */
require __DIR__ . '/_cron.php';

bharatseo_cli_cron('process_queue');
