<?php
declare(strict_types=1);

/**
 * Remove expired jobs, tokens, rate-limit rows, sessions and old files. Audit logs are kept. Run daily.
 * Usage (cPanel/aPanel): /usr/local/bin/php /home/USER/bharatseo/cron/cleanup.php
 */
require __DIR__ . '/_cron.php';

bharatseo_cli_cron('cleanup');
