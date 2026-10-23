<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Unit;

use BharatSeo\Core\Logger;
use BharatSeo\Security\Auth;
use BharatSeo\Security\Crypto;
use BharatSeo\Services\Mail\MailException;
use BharatSeo\Services\Mail\MailMessage;
use BharatSeo\Support\Csv;
use BharatSeo\Tests\TestCase;

final class SecurityPrimitivesTest extends TestCase
{
    public function testCsvFormulaInjectionIsNeutralised(): void
    {
        foreach (['=HYPERLINK("http://x")', '+1+1', '-2+3', '@SUM(A1)', "\tcmd", '|calc'] as $bad) {
            $this->assertTrue(str_starts_with(Csv::safeCell($bad), "'"), "Cell '$bad' must be prefixed");
        }
        $this->assertSame('-42', Csv::safeCell('-42'), 'Plain negative numbers stay numeric');
        $this->assertSame('https://example.com', Csv::safeCell('https://example.com'));
        $out = fopen('php://memory', 'w+');
        Csv::writeRow($out, ['=1+1', 'ok']);
        rewind($out);
        $this->assertSame("'=1+1,ok\n", stream_get_contents($out));
    }

    public function testCsvHeaderMappingForCompetitorExports(): void
    {
        $ahrefs = ['Referring page title', 'Referring page URL', 'Target URL', 'Anchor', 'Domain rating'];
        $map = Csv::mapHeaders($ahrefs, \BharatSeo\Services\DiscoveryService::ALIASES);
        $this->assertSame(1, $map['url']);
        $this->assertSame(0, $map['title']);
        $this->assertSame(2, $map['competitor_target']);
        $this->assertSame(3, $map['anchor']);
        $semrush = ['Page ascore', 'Source title', 'Source url', 'Target url', 'Anchor'];
        $this->assertSame(2, Csv::mapHeaders($semrush, \BharatSeo\Services\DiscoveryService::ALIASES)['url']);
        $this->assertSame("\t", Csv::detectDelimiter("a\tb\tc"));
        $this->assertSame(';', Csv::detectDelimiter('a;b;c'));
    }

    public function testCryptoRoundTripAndTamperDetection(): void
    {
        $c = new Crypto('base64:' . base64_encode(random_bytes(32)));
        $enc = $c->encrypt('smtp-secret-123');
        $this->assertNotContains('smtp-secret-123', $enc);
        $this->assertSame('smtp-secret-123', $c->decrypt($enc));
        $tampered = 'v1:' . base64_encode(substr_replace(base64_decode(substr($enc, 3)), 'X', -1, 1));
        $this->assertThrows(\RuntimeException::class, fn() => $c->decrypt($tampered));
        $other = new Crypto('base64:' . base64_encode(random_bytes(32)));
        $this->assertThrows(\RuntimeException::class, fn() => $other->decrypt($enc));
        $this->assertThrows(\RuntimeException::class, fn() => new Crypto('short'));
    }

    public function testPasswordPolicyAndHashing(): void
    {
        $this->assertTrue(Auth::passwordProblems('short1!') !== []);
        $this->assertTrue(Auth::passwordProblems('onlyletterslong') !== []);
        $this->assertTrue(Auth::passwordProblems('priya2026longpass', 'priya@x.in') !== []);
        $this->assertSame([], Auth::passwordProblems('Correct-Horse-9', 'admin@x.in'));
        $h = Auth::hashPassword('Correct-Horse-9');
        $this->assertTrue(password_verify('Correct-Horse-9', $h));
        $this->assertFalse(str_contains($h, 'Correct'));
    }

    public function testLogRedaction(): void
    {
        $r = Logger::redact(['smtp_pass' => 'p@ss', 'nested' => ['api_key' => 'k', 'note' => 'url https://user:secret@host/x token=abc123']]);
        $this->assertSame('[REDACTED]', $r['smtp_pass']);
        $this->assertSame('[REDACTED]', $r['nested']['api_key']);
        $this->assertNotContains('secret', $r['nested']['note']);
        $this->assertNotContains('abc123', $r['nested']['note']);
        $this->assertNotContains('xyz', Logger::redactString('Authorization: Bearer xyz.abc'));
    }

    public function testMailHeaderInjectionIsRejected(): void
    {
        $this->assertThrows(MailException::class, fn() => new MailMessage('a@b.in', 'Me', 'c@d.in', null, "Hi\r\nBcc: victim@x.in", 'body'));
        $this->assertThrows(MailException::class, fn() => new MailMessage('a@b.in', 'Me', "c@d.in\r\nBcc: x@y.in", null, 'Hi', 'body'));
        $m = new MailMessage('a@b.in', 'Bharat SEO', 'c@d.in', 'Ravi', 'नमस्ते', "Line 1\n.leading dot");
        $mime = $m->toMime('<id@b.in>');
        $this->assertContains('Subject: =?UTF-8?B?', $mime);
        $this->assertContains('Content-Transfer-Encoding: quoted-printable', $mime);
    }
}
