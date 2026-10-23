<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Support;

use BharatSeo\Crawler\DnsResolver;
use BharatSeo\Crawler\HttpResponse;
use BharatSeo\Crawler\PageFetcher;
use BharatSeo\Services\Mail\MailException;
use BharatSeo\Services\Mail\MailMessage;
use BharatSeo\Services\Mail\MailTransport;

/** DNS resolver returning fixed answers. */
final class FakeResolver implements DnsResolver
{
    /** @param array<string,string[]> $map */
    public function __construct(private array $map)
    {
    }

    public function resolve(string $host): array
    {
        return $this->map[$host] ?? [];
    }
}

/**
 * Fetcher returning scripted responses. A URL may map to a single response or a queue of
 * responses (consumed in order; the last one repeats).
 */
final class FakeFetcher implements PageFetcher
{
    /** @var array<string, HttpResponse[]> */
    private array $routes = [];
    public array $requests = [];

    public function on(string $url, HttpResponse ...$responses): self
    {
        $this->routes[$url] = $responses;
        return $this;
    }

    public static function html(string $url, string $html, int $status = 200, array $redirects = [], ?string $finalUrl = null): HttpResponse
    {
        return new HttpResponse($url, $finalUrl ?? $url, $status, ['content-type' => 'text/html; charset=utf-8'], $html, null, null, $redirects, 120);
    }

    public static function status(string $url, int $status): HttpResponse
    {
        return new HttpResponse($url, $url, $status, ['content-type' => 'text/html'], '<html><body>error</body></html>', null, null, [], 50);
    }

    public static function networkError(string $url): HttpResponse
    {
        return HttpResponse::failure($url, HttpResponse::ERR_NETWORK, 'Network error: Connection timed out');
    }

    public function fetch(string $url, array $options = []): HttpResponse
    {
        $this->requests[] = $url;
        if (!isset($this->routes[$url])) {
            return HttpResponse::failure($url, HttpResponse::ERR_NETWORK, 'No fake route for ' . $url);
        }
        $q = &$this->routes[$url];
        return count($q) > 1 ? array_shift($q) : $q[0];
    }
}

/** Mail transport capturing messages; can be told to fail. */
final class FakeTransport implements MailTransport
{
    /** @var MailMessage[] */
    public array $sent = [];
    public ?MailException $failWith = null;

    public function send(MailMessage $message): string
    {
        if ($this->failWith) {
            throw $this->failWith;
        }
        $this->sent[] = $message;
        return '<fake-' . count($this->sent) . '@test>';
    }
}
