<?php
declare(strict_types=1);

namespace Flatmates\Core;

/** Raised by services when user input fails validation. */
final class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message);
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => $message], $message);
    }
}
