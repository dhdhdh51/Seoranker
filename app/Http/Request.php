<?php
declare(strict_types=1);

namespace Flatmates\Http;

final class Request
{
    public function __construct(
        private array $get,
        private array $post,
        private array $server,
        private array $files = [],
        private array $cookies = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);
    }

    public function method(): string
    {
        return strtoupper((string)($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /** Route path relative to the app base, e.g. "/projects/5". */
    public function path(string $basePath): string
    {
        $uri = (string)($this->server['REQUEST_URI'] ?? '/');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = rawurldecode($path);
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }
        $path = '/' . trim($path, '/');
        return $path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        $v = $this->get[$key] ?? $default;
        return is_array($v) ? $default : $v;
    }

    public function queryInt(string $key): ?int
    {
        $v = $this->query($key);
        return is_string($v) && preg_match('/^\d{1,10}$/', $v) ? (int)$v : null;
    }

    public function queryAll(): array
    {
        return array_filter($this->get, static fn($v) => is_scalar($v));
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function inputInt(string $key): ?int
    {
        $v = $this->input($key);
        return is_scalar($v) && preg_match('/^\d{1,10}$/', (string)$v) ? (int)$v : null;
    }

    /** @return int[] */
    public function inputIds(string $key): array
    {
        $v = $this->post[$key] ?? [];
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_unique(array_map('intval', array_filter($v, static fn($x) => is_scalar($x) && preg_match('/^\d{1,10}$/', (string)$x)))));
    }

    public function all(): array
    {
        return $this->post;
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        return is_array($f) ? $f : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? (string)$this->server[$key] : null;
    }

    public function ip(array $trustedProxies = []): string
    {
        $remote = (string)($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($trustedProxies && in_array($remote, $trustedProxies, true)) {
            $fwd = (string)($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
            $first = trim(explode(',', $fwd)[0] ?? '');
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    public function isHttps(): bool
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') || (int)($this->server['SERVER_PORT'] ?? 0) === 443;
    }

    public function userAgent(): string
    {
        return mb_substr((string)($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function referer(): ?string
    {
        return isset($this->server['HTTP_REFERER']) ? (string)$this->server['HTTP_REFERER'] : null;
    }

    public function uri(): string
    {
        return (string)($this->server['REQUEST_URI'] ?? '/');
    }
}
