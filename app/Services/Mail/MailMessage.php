<?php
declare(strict_types=1);

namespace BharatSeo\Services\Mail;

/** Plain-text email with header-injection protection. */
final class MailMessage
{
    /** @var array<string,string> */
    private array $extraHeaders = [];

    public function __construct(
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly string $toEmail,
        public readonly ?string $toName,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $replyTo = null,
        public ?string $messageId = null,
    ) {
        foreach ([$fromEmail, $toEmail, $replyTo ?? ''] as $addr) {
            if ($addr !== '' && !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                throw new MailException('Invalid email address: ' . self::safe($addr));
            }
        }
        foreach ([$fromName, $toName ?? '', $subject] as $h) {
            if (preg_match('/[\r\n\0]/', $h)) {
                throw new MailException('Header values must not contain line breaks.');
            }
        }
    }

    public function withHeader(string $name, string $value): self
    {
        if (!preg_match('/^[A-Za-z0-9\-]+$/', $name) || preg_match('/[\r\n\0]/', $value)) {
            throw new MailException('Invalid header.');
        }
        $clone = clone $this;
        $clone->extraHeaders[$name] = $value;
        return $clone;
    }

    /** @return string[] */
    public function recipients(): array
    {
        return [$this->toEmail];
    }

    public function toMime(string $messageId): string
    {
        $h = [];
        $h[] = 'Date: ' . date(DATE_RFC2822);
        $h[] = 'Message-ID: ' . $messageId;
        $h[] = 'From: ' . self::address($this->fromEmail, $this->fromName);
        $h[] = 'To: ' . self::address($this->toEmail, $this->toName);
        if ($this->replyTo) {
            $h[] = 'Reply-To: ' . $this->replyTo;
        }
        $h[] = 'Subject: ' . self::encodeHeader($this->subject);
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Content-Type: text/plain; charset=UTF-8';
        $h[] = 'Content-Transfer-Encoding: quoted-printable';
        $h[] = 'X-Mailer: BharatSEO/' . (defined('APP_VERSION') ? APP_VERSION : '1');
        foreach ($this->extraHeaders as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $body = quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $this->body));
        return implode("\r\n", $h) . "\r\n\r\n" . $body;
    }

    private static function address(string $email, ?string $name): string
    {
        return ($name !== null && $name !== '') ? self::encodeHeader($name) . ' <' . $email . '>' : $email;
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    private static function safe(string $s): string
    {
        return substr(preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '', 0, 100);
    }
}
