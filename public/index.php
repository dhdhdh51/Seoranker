<?php
declare(strict_types=1);

/**
 * Bharat SEO front controller. Point your domain's document root at this
 * "public" directory so application code, config and storage stay outside it.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

\BharatSeo\Http\Kernel::handle();
