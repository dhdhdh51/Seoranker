<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Services\Mail\LogTransport;
use BharatSeo\Services\Mail\MailException;
use BharatSeo\Services\Mail\MailMessage;
use BharatSeo\Services\Mail\MailTransport;
use BharatSeo\Services\Mail\SmtpTransport;

/** Builds the configured transport. The log driver is refused in production. */
final class MailerService
{
    private ?MailTransport $override = null;

    public function __construct(private App $app)
    {
    }

    public function setTransport(?MailTransport $t): void
    {
        $this->override = $t;
    }

    public function isConfigured(): bool
    {
        if ($this->override) {
            return true;
        }
        $s = $this->app->settings();
        if ($s->get('smtp.driver') === 'log') {
            return !$this->app->config->isProduction() && $s->get('smtp.from_email') !== '';
        }
        return $s->get('smtp.host') !== '' && $s->get('smtp.from_email') !== '';
    }

    public function transport(): MailTransport
    {
        if ($this->override) {
            return $this->override;
        }
        $s = $this->app->settings();
        if ($s->get('smtp.driver') === 'log') {
            if ($this->app->config->isProduction()) {
                throw new MailException('The log mail driver is disabled in production. Configure SMTP.');
            }
            return new LogTransport($this->app->config->storagePath('mail'));
        }
        if (!$this->isConfigured()) {
            throw new MailException('SMTP is not configured.');
        }
        $helo = parse_url((string)$this->app->config->get('app.url', ''), PHP_URL_HOST) ?: 'localhost';
        return new SmtpTransport(
            (string)$s->get('smtp.host'),
            (int)$s->get('smtp.port'),
            (string)$s->get('smtp.encryption'),
            (string)$s->get('smtp.username'),
            (string)$s->get('smtp.password'),
            (int)$s->get('smtp.timeout'),
            (string)$helo
        );
    }

    public function fromEmail(): string
    {
        return (string)$this->app->settings()->get('smtp.from_email');
    }

    public function fromName(): string
    {
        return (string)$this->app->settings()->get('smtp.from_name');
    }

    public function sendSystemMail(string $to, string $subject, string $body): string
    {
        $msg = new MailMessage($this->fromEmail(), $this->fromName(), $to, null, $subject, $body);
        return $this->transport()->send($msg);
    }
}
