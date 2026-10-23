<?php
declare(strict_types=1);

namespace BharatSeo\Jobs;

/**
 * A queue job handler. Handlers MUST be idempotent: running the same payload
 * twice must not create duplicate records or send duplicate emails.
 */
interface JobHandler
{
    /** @param array $job the job_queue row (payload decoded into $job['data']) */
    public function handle(array $job): void;
}
