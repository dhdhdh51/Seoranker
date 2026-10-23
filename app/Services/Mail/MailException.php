<?php
declare(strict_types=1);

namespace BharatSeo\Services\Mail;

final class MailException extends \RuntimeException
{
    /** 5xx SMTP replies are permanent; connection problems and 4xx are temporary. */
    public function isPermanent(): bool
    {
        return $this->getCode() >= 500 && $this->getCode() < 600;
    }
}
