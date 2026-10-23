<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Unit;

use BharatSeo\Crawler\SafeUrlValidator;
use BharatSeo\Tests\Support\FakeResolver;
use BharatSeo\Tests\TestCase;

final class SafeUrlValidatorTest extends TestCase
{
    private SafeUrlValidator $v;

    public function setUp(): void
    {
        $this->v = new SafeUrlValidator(new FakeResolver([
            'public.example' => ['93.184.216.34'],
            'rebind.example' => ['93.184.216.34', '10.0.0.5'],
            'internal-alias.example' => ['127.0.0.1'],
            'metadata-alias.example' => ['169.254.169.254'],
            'v6private.example' => ['fd00::1'],
            'mapped.example' => ['::ffff:127.0.0.1'],
            'cgnat.example' => ['100.64.1.1'],
            'v6public.example' => ['2606:2800:220:1:248:1893:25c8:1946'],
        ]));
    }

    private function blocked(string $url, string $why = ''): void
    {
        $r = $this->v->validate($url);
        $this->assertFalse($r['ok'], "Expected $url to be blocked $why");
        $this->assertTrue(is_string($r['reason']) && $r['reason'] !== '', 'A rejection reason must be recorded');
    }

    public function testAllowsPublicHosts(): void
    {
        $r = $this->v->validate('https://public.example/page');
        $this->assertTrue($r['ok']);
        $this->assertSame('93.184.216.34', $r['ip'], 'Validated IP must be returned for connection pinning');
        $this->assertTrue($this->v->validate('https://v6public.example/')['ok']);
        $this->assertTrue($this->v->validate('http://93.184.216.34/')['ok']);
    }

    public function testBlocksLoopbackPrivateLinkLocalAndMetadata(): void
    {
        foreach (['http://127.0.0.1/', 'http://localhost/', 'http://10.1.2.3/', 'http://192.168.1.1/', 'http://172.16.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'http://[fe80::1]/', 'http://0.0.0.0/', 'http://metadata.google.internal/', 'http://instance-data/', 'http://printer.local/', 'http://app.internal/'] as $u) {
            $this->blocked($u);
        }
    }

    public function testBlocksDnsAnswersPointingInternally(): void
    {
        $this->blocked('https://internal-alias.example/', '(resolves to loopback)');
        $this->blocked('https://metadata-alias.example/', '(resolves to metadata IP)');
        $this->blocked('https://rebind.example/', '(any private answer must reject)');
        $this->blocked('https://v6private.example/', '(ULA)');
        $this->blocked('https://mapped.example/', '(IPv4-mapped loopback)');
        $this->blocked('https://cgnat.example/', '(CGNAT)');
        $this->blocked('https://nxdomain.example/', '(unresolvable)');
    }

    public function testBlocksObfuscatedNumericHosts(): void
    {
        foreach (['http://2130706433/', 'http://0x7f000001/', 'http://0177.0.0.1/', 'http://127.1/', 'http://0x7f.1/'] as $u) {
            $this->blocked($u);
        }
    }

    public function testBlocksBadSchemesPortsAndCredentials(): void
    {
        foreach (['ftp://public.example/', 'file:///etc/passwd', 'gopher://public.example/', 'http://user:pass@public.example/', 'http://public.example@127.0.0.1/', 'http://public.example:22/', 'http://public.example:6379/', "http://public.example/\r\nX: y", 'dict://public.example:11211/'] as $u) {
            $this->blocked($u);
        }
    }

    public function testCidrMath(): void
    {
        $this->assertTrue(SafeUrlValidator::inCidr('172.31.255.255', '172.16.0.0/12'));
        $this->assertFalse(SafeUrlValidator::inCidr('172.32.0.1', '172.16.0.0/12'));
        $this->assertTrue(SafeUrlValidator::inCidr('fe80::abcd', 'fe80::/10'));
        $this->assertFalse(SafeUrlValidator::isPublicIp('255.255.255.255'));
        $this->assertTrue(SafeUrlValidator::isPublicIp('8.8.8.8'));
    }
}
