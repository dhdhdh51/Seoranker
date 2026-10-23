<?php
declare(strict_types=1);

namespace BharatSeo\Crawler;

use BharatSeo\Core\App;
use BharatSeo\Security\RateLimiter;

/**
 * Resource-limited, SSRF-safe cURL client for crawling public pages.
 *
 * - validates every URL (and every redirect hop) with SafeUrlValidator;
 * - pins the validated IP with CURLOPT_RESOLVE (no DNS rebinding);
 * - follows redirects manually up to a limit;
 * - limits connect/total time and response size, validates content type;
 * - respects robots.txt and a per-domain request interval;
 * - retries temporary failures with exponential backoff (bounded).
 */
class HttpClient implements PageFetcher
{
    protected App $app;
    protected SafeUrlValidator $validator;
    /** @var array<string, RobotsTxt> */
    private array $robotsMemo = [];

    public function __construct(App $app, ?SafeUrlValidator $validator = null)
    {
        $this->app = $app;
        $this->validator = $validator ?? new SafeUrlValidator();
    }

    public function userAgent(): string
    {
        $default = 'BharatSEOBot/1.0 (+' . rtrim((string)$this->app->config->get('app.url', ''), '/') . '/bot.html)';
        $ua = (string)$this->setting('crawler.user_agent', '');
        return $ua !== '' ? $ua : $default;
    }

    protected function setting(string $key, mixed $default): mixed
    {
        try {
            return $this->app->settings()->get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    public function fetch(string $url, array $options = []): HttpResponse
    {
        $respectRobots = $options['respect_robots'] ?? true;
        $accept = $options['accept'] ?? ['text/html', 'application/xhtml+xml'];
        $maxBytes = (int)($options['max_bytes'] ?? $this->setting('crawler.max_bytes', 2_000_000));
        $maxRedirects = (int)$this->setting('crawler.max_redirects', 5);
        $retries = max(0, min(3, (int)$this->setting('crawler.retries', 2)));
        $started = microtime(true);

        $current = $url;
        $chain = [];
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $check = $this->validator->validate($current);
            if (!$check['ok']) {
                return HttpResponse::failure($current, HttpResponse::ERR_BLOCKED, 'Rejected: ' . $check['reason'], $chain);
            }
            if ($respectRobots) {
                $robots = $this->robotsFor($check['scheme'], $check['host'], $check['port']);
                if (!$robots->isAllowed($current, $this->userAgent())) {
                    return HttpResponse::failure($current, HttpResponse::ERR_ROBOTS, 'Disallowed by robots.txt', $chain);
                }
                $delay = $robots->crawlDelay($this->userAgent());
            } else {
                $delay = null;
            }
            // robots.txt itself (and owner-requested verification fetches) do not consume the per-domain slot,
            // so the first real page request to a new domain is not deferred by its own robots.txt lookup.
            if (($options['rate_limit'] ?? $respectRobots) && !$this->waitForSlot($check['host'], $delay)) {
                return HttpResponse::failure($current, HttpResponse::ERR_RATE_LIMITED, 'Per-domain request rate limit reached; will retry later.', $chain);
            }

            $attempt = 0;
            do {
                $raw = $this->rawRequest($current, $check['host'], $check['port'], $check['ip'], $accept, $maxBytes);
                $temporary = $raw['error_type'] === HttpResponse::ERR_NETWORK || in_array($raw['status'], [429, 502, 503, 504], true);
                if (!$temporary || $attempt >= $retries) {
                    break;
                }
                $retryAfter = (int)($raw['headers']['retry-after'] ?? 0);
                if ($retryAfter > 5) {
                    break; // do not block the worker; job-level backoff will retry
                }
                $sleepMs = max($retryAfter * 1000, (int)(500 * (2 ** $attempt)) + random_int(0, 250));
                usleep($sleepMs * 1000);
                $attempt++;
            } while (true);

            if ($raw['error_type'] !== null) {
                return new HttpResponse($url, $current, $raw['status'], $raw['headers'], '', $raw['error_type'], $raw['error'], $chain, (int)((microtime(true) - $started) * 1000));
            }
            if ($raw['status'] >= 300 && $raw['status'] < 400 && !empty($raw['headers']['location'])) {
                $next = Url::resolve($current, $raw['headers']['location']);
                if ($next === null) {
                    return HttpResponse::failure($current, HttpResponse::ERR_BLOCKED, 'Redirect to a non-HTTP(S) location was refused.', $chain, $raw['status']);
                }
                $chain[] = $current;
                $current = $next;
                continue;
            }
            return new HttpResponse($url, $current, $raw['status'], $raw['headers'], $raw['body'], null, null, $chain, (int)((microtime(true) - $started) * 1000), $raw['truncated']);
        }
        return HttpResponse::failure($current, HttpResponse::ERR_REDIRECTS, 'Too many redirects.', $chain);
    }

    /** Respect the configured per-domain interval (and robots Crawl-delay). Waits up to 5 seconds. */
    protected function waitForSlot(string $host, ?float $crawlDelay): bool
    {
        $interval = (int)$this->setting('crawler.domain_interval_ms', 3000);
        if ($crawlDelay !== null) {
            $interval = max($interval, (int)($crawlDelay * 1000));
        }
        if ($interval <= 0) {
            return true;
        }
        try {
            $limiter = $this->app->make(RateLimiter::class);
            $key = 'crawl:' . Url::registrableDomain($host);
            for ($i = 0; $i < 3; $i++) {
                $wait = $limiter->acquireSlot($key, $interval);
                if ($wait === 0) {
                    return true;
                }
                if ($wait > 5000) {
                    return false;
                }
                usleep(($wait + 50) * 1000);
            }
            return false;
        } catch (\Throwable $e) {
            $this->app->logger()->warning('Rate limiter unavailable', ['error' => $e->getMessage()]);
            return true;
        }
    }

    public function robotsFor(string $scheme, string $host, int $port): RobotsTxt
    {
        $key = "$scheme://$host:$port";
        if (isset($this->robotsMemo[$key])) {
            return $this->robotsMemo[$key];
        }
        $db = null;
        try {
            $db = $this->app->db();
            $row = $db->fetch('SELECT status_code, body, fetched_at FROM robots_cache WHERE host = ?', [$key]);
            if ($row) {
                $age = time() - strtotime($row['fetched_at'] . ' UTC');
                $ttl = ((int)$row['status_code'] >= 500 || (int)$row['status_code'] === 0) ? 3600 : 86400;
                if ($age < $ttl) {
                    return $this->robotsMemo[$key] = self::robotsFromStatus((int)$row['status_code'], (string)$row['body']);
                }
            }
        } catch (\Throwable) {
            $db = null;
        }
        $robotsUrl = $scheme . '://' . $host . ((($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) ? ':' . $port : '') . '/robots.txt';
        $resp = $this->fetch($robotsUrl, ['respect_robots' => false, 'accept' => ['text/plain', 'text/html', ''], 'max_bytes' => 500_000]);
        $status = $resp->errorType === null ? $resp->status : 0;
        $body = $status >= 200 && $status < 300 ? $resp->body : '';
        try {
            $db?->run(
                'INSERT INTO robots_cache (host, status_code, body, fetched_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE status_code = VALUES(status_code), body = VALUES(body), fetched_at = VALUES(fetched_at)',
                [$key, $status, $body]
            );
        } catch (\Throwable) {
        }
        return $this->robotsMemo[$key] = self::robotsFromStatus($status, $body);
    }

    /** RFC 9309: 2xx parse; 4xx allow all; 5xx/unreachable disallow all (temporarily). */
    public static function robotsFromStatus(int $status, string $body): RobotsTxt
    {
        if ($status >= 200 && $status < 300) {
            return new RobotsTxt($body);
        }
        if ($status >= 400 && $status < 500) {
            return RobotsTxt::allowAll();
        }
        return RobotsTxt::disallowAll();
    }

    /**
     * Perform one HTTP GET without following redirects, connecting only to the pinned IP.
     * @return array{status:int, headers:array<string,string>, body:string, error_type:?string, error:?string, truncated:bool}
     */
    protected function rawRequest(string $url, string $host, int $port, string $ip, array $accept, int $maxBytes): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error_type' => HttpResponse::ERR_NETWORK, 'error' => 'cURL extension is not available.', 'truncated' => false];
        }
        $headers = [];
        $body = '';
        $tooLarge = false;
        $badType = false;
        $ch = curl_init();
        $resolveIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [trim($host, '[]') . ':' . $port . ':' . $resolveIp],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => (int)$this->setting('crawler.connect_timeout', 5),
            CURLOPT_TIMEOUT => (int)$this->setting('crawler.timeout', 15),
            CURLOPT_USERAGENT => $this->userAgent(),
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,text/plain;q=0.5,*/*;q=0.1', 'Accept-Language: en;q=0.9,*;q=0.5'],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $len = strlen($line);
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge, &$badType, &$headers, $maxBytes, $accept): int {
                if ($body === '') {
                    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    $ct = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
                    if ($status >= 200 && $status < 300 && !in_array('', $accept, true) && $ct !== '' && !in_array($ct, $accept, true)) {
                        $badType = true;
                        return -1;
                    }
                }
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return -1;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($badType) {
            return ['status' => $status, 'headers' => $headers, 'body' => '', 'error_type' => HttpResponse::ERR_CONTENT_TYPE, 'error' => 'Unsupported content type: ' . ($headers['content-type'] ?? 'unknown'), 'truncated' => false];
        }
        if ($tooLarge || $errno === CURLE_FILESIZE_EXCEEDED) {
            return ['status' => $status, 'headers' => $headers, 'body' => '', 'error_type' => HttpResponse::ERR_TOO_LARGE, 'error' => 'Response exceeded the size limit of ' . $maxBytes . ' bytes.', 'truncated' => true];
        }
        if ($errno !== 0) {
            return ['status' => $status, 'headers' => $headers, 'body' => '', 'error_type' => HttpResponse::ERR_NETWORK, 'error' => 'Network error: ' . substr($error, 0, 200), 'truncated' => false];
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error_type' => null, 'error' => null, 'truncated' => false];
    }
}
