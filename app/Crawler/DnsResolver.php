<?php
declare(strict_types=1);

namespace BharatSeo\Crawler;

/** Resolves a hostname to IP addresses (A + AAAA). Injectable for tests. */
interface DnsResolver
{
    /** @return string[] */
    public function resolve(string $host): array;
}
