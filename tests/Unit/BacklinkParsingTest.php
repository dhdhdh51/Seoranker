<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Unit;

use BharatSeo\Crawler\HtmlParser;
use BharatSeo\Crawler\HttpResponse;
use BharatSeo\Services\BacklinkMonitor;
use BharatSeo\Tests\Support\FakeFetcher;
use BharatSeo\Tests\TestCase;

/** Uses the local fixture tests/fixtures/referring_page.html. */
final class BacklinkParsingTest extends TestCase
{
    private const PAGE = 'https://www.krishihub.example/resources/organic-farming/';
    private const TARGET = 'https://greenfarm.in/guides/composting/';
    private string $html;

    public function setUp(): void
    {
        $this->html = (string)file_get_contents(dirname(__DIR__) . '/fixtures/referring_page.html');
    }

    public function testParserExtractsMetadataLinksAndContacts(): void
    {
        $p = HtmlParser::parse($this->html, self::PAGE);
        $this->assertSame('Best Organic Farming Resources for Indian Growers | Krishi Hub', $p['title']);
        $this->assertSame('Krishi Hub', $p['site_name']);
        $this->assertSame('https://www.krishihub.example/resources/organic-farming/', $p['canonical']);
        $this->assertTrue($p['has_author']);
        $this->assertTrue($p['has_date']);
        $hrefs = array_column($p['links'], 'href');
        $this->assertTrue(in_array('https://www.krishihub.example/resources/organic-certification.html', $hrefs, true), '<base href> must be honoured for relative links');
        $this->assertFalse(in_array('https://evil.example/', $hrefs, true), 'Script-generated links must never appear (no JS execution)');
        foreach ($hrefs as $h) {
            $this->assertTrue(str_starts_with($h, 'http'), 'javascript:/mailto: links are not treated as links');
        }
        $this->assertTrue(in_array('editor@krishihub.example', $p['emails'], true));
        $hidden = array_values(array_filter($p['links'], static fn($l) => $l['hidden']));
        $this->assertSame(1, count($hidden));
        $this->assertSame('https://hidden-casino.example/', $hidden[0]['href']);
        $sidebar = array_values(array_filter($p['links'], static fn($l) => $l['href'] === 'https://ads.example.com/'));
        $this->assertFalse($sidebar[0]['in_content'], 'Sidebar links are not in-content');
    }

    public function testDetectsActiveLinkWithAnchorAndNoRel(): void
    {
        $o = BacklinkMonitor::observe(FakeFetcher::html(self::PAGE, $this->html), self::PAGE, self::TARGET, 'greenfarm.in', 'Home composting guide by Green Farm');
        $this->assertSame('present', $o['observation']);
        $this->assertSame('active', $o['candidate_status']);
        $this->assertTrue($o['link_present']);
        $this->assertSame('Home composting guide by Green Farm', $o['anchor']);
        $this->assertSame(null, $o['rel']);
    }

    public function testDetectsChangedAnchor(): void
    {
        $o = BacklinkMonitor::observe(FakeFetcher::html(self::PAGE, $this->html), self::PAGE, self::TARGET, 'greenfarm.in', 'Green Farm');
        $this->assertSame('changed_anchor', $o['candidate_status']);
    }

    public function testDetectsNofollowSponsoredRel(): void
    {
        $o = BacklinkMonitor::observe(FakeFetcher::html(self::PAGE, $this->html), self::PAGE, 'https://greenfarm.in/shop/', 'greenfarm.in', null);
        $this->assertSame('nofollow', $o['candidate_status']);
        $this->assertTrue($o['nofollow']);
        $this->assertTrue($o['sponsored']);
        $this->assertSame('nofollow sponsored', $o['rel']);
    }

    public function testNotPointingAndMissing(): void
    {
        $o = BacklinkMonitor::observe(FakeFetcher::html(self::PAGE, $this->html), self::PAGE, 'https://greenfarm.in/other-page/', 'greenfarm.in', null);
        $this->assertSame('not_pointing', $o['observation']);
        $this->assertFalse($o['link_present']);
        $o2 = BacklinkMonitor::observe(FakeFetcher::html(self::PAGE, '<html><body><p>No links</p></body></html>'), self::PAGE, self::TARGET, 'greenfarm.in', null);
        $this->assertSame('missing', $o2['observation']);
    }

    public function testErrorClassification(): void
    {
        $this->assertSame('page_gone', BacklinkMonitor::observe(FakeFetcher::status(self::PAGE, 404), self::PAGE, self::TARGET, 'greenfarm.in', null)['observation']);
        $this->assertSame('temporary_error', BacklinkMonitor::observe(FakeFetcher::status(self::PAGE, 503), self::PAGE, self::TARGET, 'greenfarm.in', null)['observation']);
        $this->assertSame('temporary_error', BacklinkMonitor::observe(FakeFetcher::networkError(self::PAGE), self::PAGE, self::TARGET, 'greenfarm.in', null)['observation']);
        $this->assertSame('robots_blocked', BacklinkMonitor::observe(HttpResponse::failure(self::PAGE, HttpResponse::ERR_ROBOTS, 'robots'), self::PAGE, self::TARGET, 'greenfarm.in', null)['observation']);
        $this->assertSame('temporary_error', BacklinkMonitor::observe(FakeFetcher::status(self::PAGE, 429), self::PAGE, self::TARGET, 'greenfarm.in', null)['observation']);
    }

    public function testRedirectedReferringPage(): void
    {
        $resp = FakeFetcher::html(self::PAGE, $this->html, 200, [self::PAGE], 'https://www.krishihub.example/new-location/');
        $o = BacklinkMonitor::observe($resp, self::PAGE, self::TARGET, 'greenfarm.in', null);
        $this->assertSame('redirected', $o['candidate_status']);
    }
}
