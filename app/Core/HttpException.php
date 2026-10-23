<?php
declare(strict_types=1);

namespace BharatSeo\Core;

/** Exception carrying an HTTP status; message is safe to show to users. */
class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            403 => 'You do not have permission to access this resource.',
            404 => 'The requested page was not found.',
            405 => 'Method not allowed.',
            419 => 'Your session expired or the form token was invalid. Please try again.',
            422 => 'The submitted data was invalid.',
            429 => 'Too many requests. Please wait and try again.',
            default => 'An unexpected error occurred.',
        };
    }
}
