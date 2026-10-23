<?php
declare(strict_types=1);

namespace BharatSeo\Services\Mail;

/** Development transport: writes .eml files to storage/mail instead of sending. */
final class LogTransport implements MailTransport
{
    public function __construct(private string $dir)
    {
    }

    public function send(MailMessage $message): string
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true)) {
            throw new MailException('Mail log directory is not writable.');
        }
        $id = sprintf('<%s@bharatseo.dev>', bin2hex(random_bytes(10)));
        $file = $this->dir . '/' . gmdate('Ymd-His') . '-' . substr(sha1($id), 0, 8) . '.eml';
        if (@file_put_contents($file, $message->toMime($id)) === false) {
            throw new MailException('Could not write mail log file.');
        }
        return $id;
    }
}
