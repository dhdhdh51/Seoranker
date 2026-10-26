<?php
declare(strict_types=1);

/**
 * Student Flatmate Finder front controller. Point the domain's document root at this "public"
 * directory so application code, configuration and uploads stay outside the web root.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

\Flatmates\Http\Kernel::handle();
