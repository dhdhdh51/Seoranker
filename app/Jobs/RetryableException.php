<?php
declare(strict_types=1);

namespace BharatSeo\Jobs;

/** Temporary failure; the queue retries with exponential backoff. */
class RetryableException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}
