<?php
declare(strict_types=1);

/**
 * Enqueue due backlink checks and process them with retry/confirmation rules. Run hourly.
 * Usage (cPanel/aPanel): /usr/local/bin/php /home/USER/bharatseo/cron/check_backlinks.php
 */
require __DIR__ . '/_cron.php';

bharatseo_cli_cron('check_backlinks');
