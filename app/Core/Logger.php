<?php
declare(strict_types=1);

namespace Flatmates\Core;

/**
 * File logger with secret redaction. Logs live in storage/logs (not web-accessible).
 */
final class Logger
{
    private string $dir;

    private const SENSITIVE_KEYS = '/(pass(word)?|secret|token|api[_-]?key|authorization|cookie|smtp_pass|csrf|key)/i';

    public function __construct(App $app)
    {
        $this->dir = $app->config->storagePath('logs');
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function write(string $level, string $message, array $context = []): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            self::redactString($message),
            $context ? json_encode(self::redact($context), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : ''
        );
        @file_put_contents($this->dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function redact(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SENSITIVE_KEYS, $k)) {
                $out[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $out[$k] = self::redact($v);
            } elseif (is_string($v)) {
                $out[$k] = self::redactString($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** Remove obvious secrets from free text (password=..., key=..., Bearer tokens, URL credentials). */
    public static function redactString(string $s): string
    {
        $s = preg_replace('/((?:pass(?:word)?|secret|token|api[_-]?key|key)\s*[=:]\s*)[^\s&"\']+/i', '$1[REDACTED]', $s) ?? $s;
        $s = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[REDACTED]', $s) ?? $s;
        $s = preg_replace('#(://)[^/@\s:]+:[^/@\s]+@#', '$1[REDACTED]@', $s) ?? $s;
        return $s;
    }
}
