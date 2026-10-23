<?php
declare(strict_types=1);

/**
 * Ensure prospects get evaluated, refresh stale assessments, run scheduled search discovery. Run every 6 hours.
 * Usage (cPanel/aPanel): /usr/local/bin/php /home/USER/bharatseo/cron/discover.php
 */
require __DIR__ . '/_cron.php';

bharatseo_cli_cron('discover');
