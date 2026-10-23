<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Unit;

use BharatSeo\Crawler\Url;
use BharatSeo\Tests\TestCase;

final class UrlTest extends TestCase
{
    public function testNormalizeLowercasesHostStripsTrackingAndDefaultPort(): void
    {
        $this->assertSame('https://example.com/Path/?a=1&b=2', Url::normalize('HTTPS://Example.COM:443/Path/?b=2&utm_source=x&a=1#frag'));
        $this->assertSame('http://example.com/', Url::normalize('http://example.com:80'));
        $this->assertSame('https://example.com/a/c', Url::normalize('https://example.com/a/b/../c'));
        $this->assertSame('https://example.org/page', Url::normalize('example.org/page'));
    }

    public function testNormalizeRejectsNonHttpAndGarbage(): void
    {
        $this->assertSame(null, Url::normalize('ftp://example.com/file'));
        $this->assertSame(null, Url::normalize('javascript:alert(1)'));
        $this->assertSame(null, Url::normalize('not a url'));
        $this->assertSame(null, Url::normalize("https://exa mple.com/"));
        $this->assertSame(null, Url::normalize(''));
    }

    public function testIdnHostsAreConvertedToPunycode(): void
    {
        if (!function_exists('idn_to_ascii')) {
            throw new \BharatSeo\Tests\SkipTest('intl extension not available');
        }
        $this->assertSame('https://xn--h2brj9c.xn--h2brj9c/', Url::normalize('https://भारत.भारत/'));
    }

    public function testDedupeKeyIgnoresSchemeWwwTrailingSlashAndTracking(): void
    {
        $variants = ['https://www.example.com/blog/', 'http://example.com/blog', 'https://EXAMPLE.com/blog/?utm_campaign=z', 'https://example.com/blog#top'];
        $keys = array_unique(array_map(static fn($u) => Url::dedupeKey(Url::normalize($u)), $variants));
        $this->assertSame(1, count($keys), 'All variants must share a duplicate-detection key');
        $this->assertNotSame(Url::dedupeKey(Url::normalize('https://example.com/blog')), Url::dedupeKey(Url::normalize('https://example.com/blog2')));
    }

    public function testResolveRelativeReferences(): void
    {
        $base = 'https://site.example/a/b/page.html?x=1';
        $this->assertSame('https://site.example/a/b/other.html', Url::resolve($base, 'other.html'));
        $this->assertSame('https://site.example/a/c', Url::resolve($base, '../c'));
        $this->assertSame('https://site.example/root', Url::resolve($base, '/root'));
        $this->assertSame('https://cdn.example/x', Url::resolve($base, '//cdn.example/x'));
        $this->assertSame('https://site.example/a/b/page.html?y=2', Url::resolve($base, '?y=2'));
        $this->assertSame(null, Url::resolve($base, 'javascript:alert(1)'));
        $this->assertSame(null, Url::resolve($base, 'mailto:a@b.c'));
        $this->assertSame(null, Url::resolve($base, '#section'));
    }

    public function testDomainHelpers(): void
    {
        $this->assertSame('example.co.in', Url::registrableDomain('shop.example.co.in'));
        $this->assertSame('example.com', Url::registrableDomain('a.b.example.com'));
        $this->assertSame('example.com', Url::domain('https://www.example.com/x'));
        $this->assertTrue(Url::belongsToDomain('https://blog.greenfarm.in/x', 'greenfarm.in'));
        $this->assertTrue(Url::belongsToDomain('https://www.greenfarm.in/', 'www.greenfarm.in'));
        $this->assertFalse(Url::belongsToDomain('https://greenfarm.in.evil.example/', 'greenfarm.in'));
        $this->assertFalse(Url::belongsToDomain('https://notgreenfarm.in/', 'greenfarm.in'));
        $this->assertTrue(Url::sameResource('https://greenfarm.in/guides/composting/?utm_source=x', 'http://www.greenfarm.in/guides/composting'));
    }
}
