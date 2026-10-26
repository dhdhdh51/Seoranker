<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Services\Mail\LogTransport;
use Flatmates\Services\Mail\MailException;
use Flatmates\Services\Mail\MailMessage;
use Flatmates\Services\Mail\MailTransport;
use Flatmates\Services\Mail\SmtpTransport;

/**
 * Email delivery through a database queue: requests never block on SMTP, failures are retried
 * by cron (with an in-request attempt first so OTPs arrive quickly). Uses PHPMailer when it is
 * installed via Composer (vendor/), otherwise the built-in SMTP client.
 */
final class Mailer
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
        $s = $this->app->settings();
        if ($this->override) {
            return true;
        }
        if ($s->get('smtp.driver') === 'log') {
            return $this->app->config->get('app.env') !== 'production';
        }
        return $s->has('smtp.host') && $s->has('smtp.from_email');
    }

    /** Queue a templated email and try to send it immediately. Returns the queue id. */
    public function send(string $to, string $subject, string $body): int
    {
        $html = $this->render($subject, $body);
        $id = $this->app->db()->insert('mail_queue', ['to_email' => $to, 'subject' => mb_substr($subject, 0, 255), 'body' => $html]);
        $this->deliver($id);
        return $id;
    }

    public function render(string $subject, string $text): string
    {
        $p = (string)$this->app->settings()->get('brand.primary_color', '#2f6fed');
        $paras = implode('', array_map(static fn($l) => '<p style="margin:0 0 14px">' . nl2br(e($l)) . '</p>', preg_split("/\n{2,}/", trim($text)) ?: []));
        return '<!doctype html><html><body style="margin:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#1d2433">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden">'
            . '<tr><td style="background:' . e($p) . ';color:#fff;padding:18px 24px;font-size:18px;font-weight:bold">' . e(brand()) . '</td></tr>'
            . '<tr><td style="padding:24px;font-size:15px;line-height:1.55"><h1 style="font-size:19px;margin:0 0 16px">' . e($subject) . '</h1>' . $paras . '</td></tr>'
            . '<tr><td style="padding:16px 24px;font-size:12px;color:#6b7280;border-top:1px solid #eef0f5">' . e((string)$this->app->settings()->get('mail.footer')) . '<br>' . e((string)$this->app->settings()->get('brand.support_email')) . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    public function deliver(int $id): bool
    {
        $m = $this->app->db()->fetch("SELECT * FROM mail_queue WHERE id = ? AND status = 'pending'", [$id]);
        if (!$m) {
            return false;
        }
        if (!$this->isConfigured()) {
            $this->app->db()->update('mail_queue', ['last_error' => 'SMTP is not configured'], 'id = ?', [$id]);
            return false;
        }
        try {
            $this->transportSend($m['to_email'], $m['subject'], $m['body']);
            $this->app->db()->update('mail_queue', ['status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s'), 'attempts' => (int)$m['attempts'] + 1, 'last_error' => null], 'id = ?', [$id]);
            return true;
        } catch (\Throwable $e) {
            $attempts = (int)$m['attempts'] + 1;
            $permanent = $e instanceof MailException && $e->isPermanent();
            $this->app->db()->update('mail_queue', [
                'attempts' => $attempts,
                'status' => ($permanent || $attempts >= 6) ? 'failed' : 'pending',
                'send_after' => gmdate('Y-m-d H:i:s', time() + 60 * (2 ** $attempts)),
                'last_error' => mb_substr(\Flatmates\Core\Logger::redactString($e->getMessage()), 0, 500),
            ], 'id = ?', [$id]);
            return false;
        }
    }

    public function processQueue(int $limit = 30): int
    {
        $n = 0;
        foreach ($this->app->db()->fetchAll("SELECT id FROM mail_queue WHERE status = 'pending' AND send_after <= UTC_TIMESTAMP() ORDER BY id LIMIT " . max(1, $limit)) as $r) {
            $n += $this->deliver((int)$r['id']) ? 1 : 0;
        }
        return $n;
    }

    private function transportSend(string $to, string $subject, string $html): void
    {
        $s = $this->app->settings();
        $from = (string)$s->get('smtp.from_email');
        $fromName = (string)$s->get('smtp.from_name') ?: brand();
        if ($this->override) {
            $this->override->send(new MailMessage($from ?: 'noreply@example.com', $fromName, $to, null, $subject, strip_tags($html)));
            return;
        }
        if ($s->get('smtp.driver') === 'log') {
            (new LogTransport($this->app->config->storagePath('mail')))->send(new MailMessage($from ?: 'noreply@example.com', $fromName, $to, null, $subject, $html));
            return;
        }
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP();
            $m->Host = (string)$s->get('smtp.host');
            $m->Port = (int)$s->get('smtp.port');
            $enc = (string)$s->get('smtp.encryption');
            $m->SMTPSecure = $enc === 'ssl' ? 'ssl' : ($enc === 'tls' ? 'tls' : '');
            $m->SMTPAutoTLS = $enc !== 'none';
            $m->SMTPAuth = $s->has('smtp.username');
            $m->Username = (string)$s->get('smtp.username');
            $m->Password = (string)$s->get('smtp.password');
            $m->Timeout = 20;
            $m->CharSet = 'UTF-8';
            $m->setFrom($from, $fromName);
            $m->addAddress($to);
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body = $html;
            $m->AltBody = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], "\n", $html))));
            try {
                $m->send();
            } catch (\Throwable $e) {
                throw new MailException($m->ErrorInfo ?: $e->getMessage());
            }
            return;
        }
        $helo = parse_url((string)$this->app->config->get('app.url'), PHP_URL_HOST) ?: 'localhost';
        $t = new SmtpTransport((string)$s->get('smtp.host'), (int)$s->get('smtp.port'), (string)$s->get('smtp.encryption'), (string)$s->get('smtp.username'), (string)$s->get('smtp.password'), 20, (string)$helo);
        $t->send(new MailMessage($from, $fromName, $to, null, $subject, trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</td>'], "\n", $html))))));
    }
}
