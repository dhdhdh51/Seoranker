<?php
declare(strict_types=1);

namespace BharatSeo\Crawler;

/** Result of a fetch. Never throws for HTTP/network problems; inspect $errorType instead. */
final class HttpResponse
{
    public const ERR_NONE = null;
    public const ERR_BLOCKED = 'blocked_url';        // SSRF / validation rejection (permanent)
    public const ERR_ROBOTS = 'robots_disallowed';   // robots.txt disallows (permanent until policy changes)
    public const ERR_NETWORK = 'network';            // DNS/connect/timeout (temporary)
    public const ERR_TOO_LARGE = 'too_large';
    public const ERR_CONTENT_TYPE = 'content_type';
    public const ERR_REDIRECTS = 'too_many_redirects';
    public const ERR_RATE_LIMITED = 'rate_limited';  // local per-domain limiter (temporary)

    /**
     * @param array<string,string> $headers lower-cased header names
     * @param string[] $redirects chain of URLs followed
     */
    public function __construct(
        public readonly string $requestedUrl,
        public readonly string $finalUrl,
        public readonly int $status = 0,
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly ?string $errorType = null,
        public readonly ?string $error = null,
        public readonly array $redirects = [],
        public readonly int $timeMs = 0,
        public readonly bool $truncated = false,
    ) {
    }

    public static function failure(string $url, string $type, string $message, array $redirects = [], int $status = 0): self
    {
        return new self($url, $url, $status, [], '', $type, $message, $redirects);
    }

    public function ok(): bool
    {
        return $this->errorType === null && $this->status >= 200 && $this->status < 300;
    }

    public function contentType(): string
    {
        return strtolower(trim(explode(';', $this->headers['content-type'] ?? '')[0]));
    }

    public function isHtml(): bool
    {
        $ct = $this->contentType();
        return $ct === '' || in_array($ct, ['text/html', 'application/xhtml+xml'], true);
    }

    /** Temporary problems that must not be treated as a definitive negative observation. */
    public function isTemporaryFailure(): bool
    {
        if (in_array($this->errorType, [self::ERR_NETWORK, self::ERR_RATE_LIMITED], true)) {
            return true;
        }
        return $this->errorType === null && ($this->status === 429 || $this->status === 408 || $this->status >= 500);
    }

    public function wasRedirected(): bool
    {
        return $this->redirects !== [];
    }
}
