<?php
declare(strict_types=1);

namespace Flatmates\Support;

use Flatmates\Core\ValidationException;

/** Fluent server-side validation for request input. */
final class Validator
{
    private array $errors = [];
    private array $clean = [];

    public function __construct(private array $input)
    {
    }

    public static function make(array $input): self
    {
        return new self($input);
    }

    private function raw(string $field): string
    {
        $v = $this->input[$field] ?? '';
        return is_scalar($v) ? trim((string)$v) : '';
    }

    public function string(string $field, string $label, bool $required = true, int $max = 255, int $min = 0): self
    {
        $v = $this->raw($field);
        if ($v === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }
            $this->clean[$field] = $required ? '' : null;
            return $this;
        }
        if (mb_strlen($v) > $max) {
            $this->errors[$field] = "$label must be at most $max characters.";
        } elseif (mb_strlen($v) < $min) {
            $this->errors[$field] = "$label must be at least $min characters.";
        } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v)) {
            $this->errors[$field] = "$label contains invalid characters.";
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function text(string $field, string $label, bool $required = false, int $max = 20000): self
    {
        $v = str_replace("\r\n", "\n", is_scalar($this->input[$field] ?? null) ? (string)$this->input[$field] : '');
        $v = trim($v);
        if ($v === '' && $required) {
            $this->errors[$field] = "$label is required.";
        } elseif (mb_strlen($v) > $max) {
            $this->errors[$field] = "$label must be at most $max characters.";
        }
        $this->clean[$field] = $v === '' ? null : $v;
        return $this;
    }

    public function email(string $field, string $label, bool $required = true): self
    {
        $v = strtolower($this->raw($field));
        if ($v === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (!filter_var($v, FILTER_VALIDATE_EMAIL) || mb_strlen($v) > 190) {
            $this->errors[$field] = "$label must be a valid email address.";
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function url(string $field, string $label, bool $required = true): self
    {
        $v = $this->raw($field);
        if ($v === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }
            $this->clean[$field] = null;
            return $this;
        }
        $n = filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $v) ? $v : null;
        if ($n === null) {
            $this->errors[$field] = "$label must be a valid http(s) URL.";
        }
        $this->clean[$field] = $n ?? $v;
        return $this;
    }

    public function int(string $field, string $label, bool $required = true, ?int $min = null, ?int $max = null): self
    {
        $v = $this->raw($field);
        if ($v === '') {
            if ($required) {
                $this->errors[$field] = "$label is required.";
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (!preg_match('/^-?\d+$/', $v)) {
            $this->errors[$field] = "$label must be a whole number.";
            $this->clean[$field] = null;
            return $this;
        }
        $i = (int)$v;
        if (($min !== null && $i < $min) || ($max !== null && $i > $max)) {
            $this->errors[$field] = "$label is out of range.";
        }
        $this->clean[$field] = $i;
        return $this;
    }

    /** @param string[] $allowed */
    public function in(string $field, string $label, array $allowed, bool $required = true, ?string $default = null): self
    {
        $v = $this->raw($field);
        if ($v === '' && $default !== null) {
            $v = $default;
        }
        if ($v === '' && !$required) {
            $this->clean[$field] = null;
            return $this;
        }
        if (!in_array($v, $allowed, true)) {
            $this->errors[$field] = "$label has an invalid value.";
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function bool(string $field): self
    {
        $this->clean[$field] = in_array($this->input[$field] ?? null, ['1', 'on', 'yes', 'true', 1, true], true);
        return $this;
    }

    public function addError(string $field, string $message): self
    {
        $this->errors[$field] = $message;
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /** @throws ValidationException */
    public function validated(): array
    {
        if ($this->errors) {
            throw new ValidationException($this->errors);
        }
        return $this->clean;
    }

    /** Split a textarea into unique trimmed lines. */
    public static function lines(?string $text, int $maxLines = 1000, int $maxLen = 2048, bool $splitCommas = false): array
    {
        $out = [];
        foreach (preg_split($splitCommas ? '/\r\n|\r|\n|,/' : '/\r\n|\r|\n/', (string)$text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && mb_strlen($line) <= $maxLen) {
                $out[mb_strtolower($line)] = $line;
            }
            if (count($out) >= $maxLines) {
                break;
            }
        }
        return array_values($out);
    }
}
