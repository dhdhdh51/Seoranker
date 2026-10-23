<?php
declare(strict_types=1);

namespace BharatSeo\Services\Mail;

interface MailTransport
{
    /** Send and return the Message-ID. Throws MailException on failure. */
    public function send(MailMessage $message): string;
}
