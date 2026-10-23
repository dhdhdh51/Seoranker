<?php
declare(strict_types=1);

namespace BharatSeo\Tests\Unit;

use BharatSeo\Crawler\HtmlParser;
use BharatSeo\Services\QualityScorer;
use BharatSeo\Tests\TestCase;

final class QualityScorerTest extends TestCase
{
    private function richHtml(string $extra = ''): string
    {
        $para = '<p>Organic farming depends on soil health. Compost improves soil structure and organic matter, and farmers who practice crop rotation see better results over several seasons in different regions of India.</p>';
        $sentences = '';
        for ($i = 0; $i < 40; $i++) {
            $sentences .= "<p>Practical note number $i explains a distinct compost or soil health technique for organic farming households and small farms with clear steps.</p>";
        }
        return '<html><head><title>Organic farming resources and compost guides</title><meta name="description" content="Soil health and compost resources for organic farming"><meta name="author" content="Editor"><link rel="canonical" href="https://resource.example/organic/"></head><body>'
            . '<nav><a href="/about/">About</a><a href="/contact/">Contact</a></nav><article class="content"><h1>Organic farming resources</h1><time datetime="2026-01-01">Jan</time>'
            . $para . $sentences . '<ul><li><a href="https://a.example/x">A guide</a></li><li><a href="https://b.example/y">B guide</a></li></ul>' . $extra . '</article></body></html>';
    }

    private function signals(array $over = []): array
    {
        $html = $over['html'] ?? $this->richHtml();
        unset($over['html']);
        return $over + [
            'url' => 'https://resource.example/organic/',
            'final_url' => 'https://resource.example/organic/',
            'category' => 'resource_page',
            'attempted' => true,
            'fetched' => true,
            'http_status' => 200,
            'error_type' => null,
            'is_html' => true,
            'response_ms' => 400,
            'redirects' => [],
            'parsed' => HtmlParser::parse($html, 'https://resource.example/organic/'),
            'keywords' => ['organic farming', 'compost', 'soil health'],
            'topics' => [],
            'niche' => 'organic farming',
            'suggested_anchor' => 'Green Farm',
            'domain_page_count' => 1,
            'excluded' => false,
            'safe_browsing' => 'safe',
        ];
    }

    public function testWeightsSumTo100AndComponentsAddUp(): void
    {
        $this->assertSame(100, array_sum(array_column(QualityScorer::WEIGHTS, 'max')));
        $r = (new QualityScorer())->score($this->signals());
        $sum = array_sum(array_column($r['components'], 'score'));
        $this->assertSame($r['total'], $sum, 'Total must equal the sum of the displayed components');
        foreach ($r['components'] as $c) {
            $this->assertTrue($c['score'] <= $c['max'], $c['label'] . ' exceeds its weight');
            $this->assertTrue(count($c['notes']) > 0, 'Each component must explain itself');
        }
    }

    public function testRelevantEditorialPageScoresHigh(): void
    {
        $r = (new QualityScorer())->score($this->signals());
        $this->assertGreaterOrEqual(80, $r['total'], 'Strong relevant page should be high priority; got ' . json_encode(array_column($r['components'], 'score', 'key')));
        $this->assertSame('high', $r['band']);
        $this->assertFalse($r['has_critical']);
    }

    public function testBands(): void
    {
        $this->assertSame('high', QualityScorer::band(80));
        $this->assertSame('review', QualityScorer::band(79));
        $this->assertSame('review', QualityScorer::band(60));
        $this->assertSame('low', QualityScorer::band(59));
    }

    public function testMissingEvidenceIsUnknownAndEarnsNothing(): void
    {
        $r = (new QualityScorer())->score($this->signals(['fetched' => false, 'parsed' => null, 'http_status' => null, 'error_type' => 'network', 'error' => 'timeout', 'safe_browsing' => null]));
        $this->assertSame('unknown', $r['components']['relevance']['status']);
        $this->assertSame(0, $r['components']['relevance']['score']);
        $this->assertSame('unknown', $r['components']['editorial']['status']);
        $this->assertSame('partial', $r['components']['trust']['status']);
        $this->assertLess(60, $r['total']);
        $notes = implode(' ', $r['components']['trust']['notes']);
        $this->assertContains('Unknown', $notes);
    }

    public function testCriticalFlagsForceHoldEvenWithHighScore(): void
    {
        $hidden = '<p style="display:none"><a href="https://casino-bonus.example/">promo</a></p>';
        $r = (new QualityScorer())->score($this->signals(['html' => $this->richHtml($hidden)]));
        $this->assertTrue($r['has_critical']);
        $codes = array_column($r['flags'], 'code');
        $this->assertTrue(in_array('hidden_links', $codes, true));

        $spam = (new QualityScorer())->score($this->signals(['html' => $this->richHtml('<p>Best casino and payday loan offers</p>')]));
        $this->assertTrue(in_array('spam_content', array_column($spam['flags'], 'code'), true));
        $this->assertTrue($spam['has_critical']);

        $redir = (new QualityScorer())->score($this->signals(['redirects' => ['https://resource.example/organic/'], 'final_url' => 'https://other-site.example/landing']));
        $this->assertTrue(in_array('suspicious_redirect', array_column($redir['flags'], 'code'), true));
        $this->assertTrue($redir['has_critical']);

        $unsafe = (new QualityScorer())->score($this->signals(['safe_browsing' => 'unsafe']));
        $this->assertTrue(in_array('malware_suspected', array_column($unsafe['flags'], 'code'), true));
    }

    public function testThinIrrelevantPaidAndAnchorFlags(): void
    {
        $thin = '<html><head><title>Cheap phone cases</title></head><body><p>Buy phone cases. Sponsored post rates available, advertise with us.</p></body></html>';
        $r = (new QualityScorer())->score($this->signals(['html' => $thin, 'suggested_anchor' => 'best organic farming services online']));
        $codes = array_column($r['flags'], 'code');
        foreach (['thin_content', 'irrelevant', 'paid_placement', 'unnatural_anchor'] as $c) {
            $this->assertTrue(in_array($c, $codes, true), "Expected flag $c in " . implode(',', $codes));
        }
        $this->assertSame('low', $r['band']);
    }

    public function testGuestPostWithoutGuidelinesIsFlagged(): void
    {
        $r = (new QualityScorer())->score($this->signals(['category' => 'guest_post']));
        $this->assertTrue(in_array('missing_guidelines', array_column($r['flags'], 'code'), true));
    }

    public function testExcessiveOutboundLinks(): void
    {
        $links = '';
        for ($i = 0; $i < 420; $i++) {
            $links .= '<a href="https://site' . $i . '.example/">x</a> ';
        }
        $r = (new QualityScorer())->score($this->signals(['html' => $this->richHtml($links)]));
        $this->assertTrue(in_array('link_farm', array_column($r['flags'], 'code'), true));
        $this->assertTrue($r['has_critical']);
    }

    public function testNaturalAnchorsAreNotFlagged(): void
    {
        $this->assertFalse(QualityScorer::isUnnaturalAnchor('Green Farm', ['organic farming']));
        $this->assertFalse(QualityScorer::isUnnaturalAnchor('greenfarm.in', ['organic farming']));
        $this->assertTrue(QualityScorer::isUnnaturalAnchor('organic farming services', ['organic farming services']));
        $this->assertTrue(QualityScorer::isUnnaturalAnchor('buy cheap compost online services', []));
    }
}
