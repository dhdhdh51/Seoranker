<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Unit;

use BharatSeo\Crawler\HttpClient;
use BharatSeo\Crawler\RobotsTxt;
use BharatSeo\Tests\TestCase;

final class RobotsTxtTest extends TestCase
{
    private const UA = 'BharatSEOBot/1.0 (+https://seo.example/bot.html)';

    public function testSpecificGroupLongestMatchAndWildcards(): void
    {
        $r = new RobotsTxt("User-agent: *\nDisallow: /\n\nUser-agent: BharatSEOBot\nDisallow: /private/\nAllow: /private/public-page\nDisallow: /*.pdf$\nCrawl-delay: 7\n");
        $this->assertTrue($r->isAllowed('https://s.example/blog/post', self::UA));
        $this->assertFalse($r->isAllowed('https://s.example/private/x', self::UA));
        $this->assertTrue($r->isAllowed('https://s.example/private/public-page', self::UA));
        $this->assertFalse($r->isAllowed('https://s.example/files/a.pdf', self::UA));
        $this->assertTrue($r->isAllowed('https://s.example/files/a.pdf?x=1', self::UA));
        $this->assertSame(7.0, $r->crawlDelay(self::UA));
        $this->assertFalse($r->isAllowed('https://s.example/blog/post', 'OtherBot/2.0'), 'Other agents fall back to *');
    }

    public function testEmptyDisallowAndRfcStatusHandling(): void
    {
        $r = new RobotsTxt("User-agent: *\nDisallow:\n");
        $this->assertTrue($r->isAllowed('https://s.example/anything', self::UA));
        $this->assertTrue(HttpClient::robotsFromStatus(404, '')->isAllowed('https://s.example/x', self::UA), '4xx robots = allow all');
        $this->assertFalse(HttpClient::robotsFromStatus(503, '')->isAllowed('https://s.example/x', self::UA), '5xx robots = disallow all');
        $this->assertFalse(HttpClient::robotsFromStatus(0, '')->isAllowed('https://s.example/x', self::UA), 'unreachable = disallow all');
    }
}
