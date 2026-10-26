<?php
declare(strict_types=1);

namespace Flatmates\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
        public ?\Closure $stream = null,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function redirect(string $url, int $status = 303): self
    {
        // Only allow same-origin relative redirects.
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            $url = url('/');
        }
        return new self('', $status, ['Location' => $url]);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self((string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public static function csv(string $filename, \Closure $writer): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._\-]/', '_', $filename) ?? 'export.csv';
        return new self('', 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'X-Content-Type-Options' => 'nosniff',
        ], $writer);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        if ($this->stream !== null) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for spreadsheet apps
            ($this->stream)($out);
            fclose($out);
            return;
        }
        echo $this->body;
    }
}
