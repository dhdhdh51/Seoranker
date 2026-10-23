<?php
declare(strict_types=1);

namespace BharatSeo\Services\Mail;

/**
 * Minimal, dependency-free SMTP client (RFC 5321) with STARTTLS/implicit TLS
 * and AUTH LOGIN/PLAIN. Throws MailException on any failure.
 */
final class SmtpTransport implements MailTransport
{
    /** @var resource|null */
    private $socket = null;
    private array $log = [];

    public function __construct(
        private string $host,
        private int $port,
        private string $encryption, // tls | ssl | none
        private string $username,
        private string $password,
        private int $timeout = 15,
        private string $heloName = 'localhost',
    ) {
    }

    public function send(MailMessage $message): string
    {
        $messageId = $message->messageId ?? sprintf('<%s@%s>', bin2hex(random_bytes(12)), self::domainOf($message->fromEmail));
        $raw = $message->toMime($messageId);
        try {
            $this->connect();
            $this->command('MAIL FROM:<' . $message->fromEmail . '>', [250]);
            foreach ($message->recipients() as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', [250, 251]);
            }
            $this->command('DATA', [354]);
            // Dot-stuffing (RFC 5321 §4.5.2) and CRLF normalization.
            $data = preg_replace("/(?<!\r)\n/", "\r\n", $raw) ?? $raw;
            $data = preg_replace('/^\./m', '..', $data) ?? $data;
            $this->write($data . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221], false);
        } finally {
            $this->close();
        }
        return $messageId;
    }

    private function connect(): void
    {
        if ($this->host === '') {
            throw new MailException('SMTP host is not configured.');
        }
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host, 'SNI_enabled' => true]]);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new MailException('Could not connect to SMTP server: ' . ($errstr !== '' ? $errstr : 'connection failed') . " ($errno)");
        }
        stream_set_timeout($sock, $this->timeout);
        $this->socket = $sock;
        $this->expect([220]);
        $ehlo = $this->command('EHLO ' . $this->heloName, [250]);
        if ($this->encryption === 'tls') {
            if (!str_contains(strtoupper($ehlo), 'STARTTLS')) {
                throw new MailException('SMTP server does not support STARTTLS.');
            }
            $this->command('STARTTLS', [220]);
            $ok = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0));
            if ($ok !== true) {
                throw new MailException('STARTTLS negotiation failed.');
            }
            $ehlo = $this->command('EHLO ' . $this->heloName, [250]);
        }
        if ($this->username !== '') {
            if ($this->encryption === 'none') {
                throw new MailException('Refusing to send SMTP credentials over an unencrypted connection.');
            }
            $upper = strtoupper($ehlo);
            if (str_contains($upper, 'PLAIN')) {
                $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), [235], true, true);
            } else {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($this->username), [334], true, true);
                $this->command(base64_encode($this->password), [235], true, true);
            }
        }
    }

    private function command(string $cmd, array $expect, bool $strict = true, bool $sensitive = false): string
    {
        $this->log[] = 'C: ' . ($sensitive ? '[credentials]' : $cmd);
        $this->write($cmd . "\r\n");
        return $this->expect($expect, $strict);
    }

    private function write(string $data): void
    {
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new MailException('Lost connection to SMTP server while writing.');
            }
            $written += $n;
        }
    }

    private function expect(array $codes, bool $strict = true): string
    {
        $response = '';
        while (($line = @fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $meta = $this->socket ? stream_get_meta_data($this->socket) : [];
        if (!empty($meta['timed_out'])) {
            throw new MailException('SMTP server timed out.');
        }
        $code = (int)substr($response, 0, 3);
        $this->log[] = 'S: ' . trim(substr($response, 0, 200));
        if ($strict && !in_array($code, $codes, true)) {
            throw new MailException('SMTP error: ' . ($response === '' ? 'no response' : trim(substr($response, 0, 300))), $code);
        }
        return $response;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function transcript(): array
    {
        return $this->log;
    }

    private static function domainOf(string $email): string
    {
        $d = substr((string)strrchr($email, '@'), 1);
        return $d !== '' ? $d : 'bharatseo.local';
    }
}
