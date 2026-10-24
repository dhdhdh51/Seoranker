<?php
declare(strict_types=1);

namespace BharatSeo\Crawler;

/** Abstraction over the HTTP client so services can be tested with fixtures. */
interface PageFetcher
{
    /**
     * @param array{respect_robots?:bool, accept?:string[], max_bytes?:int, interval_ms?:int, rate_limit?:bool} $options
     */
    public function fetch(string $url, array $options = []): HttpResponse;
}
