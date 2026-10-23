<?php
declare(strict_types=1);

/**
 * Send APPROVED outreach within daily limits, cooldowns and suppression rules; draft due follow-ups. Run every 5-10 minutes.
 * Usage (cPanel/aPanel): /usr/local/bin/php /home/USER/bharatseo/cron/send_approved_outreach.php
 */
require __DIR__ . '/_cron.php';

bharatseo_cli_cron('send_approved_outreach');
