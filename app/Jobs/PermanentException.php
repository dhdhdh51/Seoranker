<?php
declare(strict_types=1);

namespace BharatSeo\Jobs;

/** Non-retryable failure; the job is moved to failed_jobs immediately. */
class PermanentException extends \RuntimeException
{
}
