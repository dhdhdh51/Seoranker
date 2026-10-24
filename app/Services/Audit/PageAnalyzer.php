<?php
declare(strict_types=1);

namespace BharatSeo\Services\Audit;

use BharatSeo\Crawler\HtmlParser;
use BharatSeo\Crawler\HttpResponse;
use BharatSeo\Crawler\Url;

/**
 * On-page and technical checks for one fetched page. Pure (no DB, no network).
 */
final class PageAnalyzer
{
    /** code => [severity, title, how to fix] */
    public const ISSUES = [
        // Errors
        'http_4xx' => ['error', 'Page returns a 4xx error', 'Restore the page or 301-redirect it, and update links that point to it.'],
        'http_5xx' => ['error', 'Page returns a 5xx server error', 'Check the server/application logs; search engines drop pages that keep failing.'],
        'fetch_failed' => ['error', 'Page could not be fetched', 'Check DNS, SSL and server availability for this URL.'],
        'broken_internal_link' => ['error', 'Links to a broken internal page', 'Fix or remove links that point to pages returning errors.'],
        'missing_title' => ['error', 'Missing <title>', 'Add a unique, descriptive title of 30–60 characters.'],
        'noindex' => ['error', 'Page is set to noindex', 'Remove the noindex robots directive if this page should appear in Google.'],
        'mixed_content' => ['error', 'HTTPS page loads insecure (http://) resources', 'Load every image, script, stylesheet and iframe over https://.'],
        // Warnings
        'title_too_long' => ['warning', 'Title longer than 60 characters', 'Shorten the title so it is not truncated in search results.'],
        'title_too_short' => ['warning', 'Title shorter than 15 characters', 'Write a more descriptive title with the main topic and brand.'],
        'duplicate_title' => ['warning', 'Duplicate title', 'Give every indexable page a unique title.'],
        'missing_meta_description' => ['warning', 'Missing meta description', 'Add a 70–160 character description that invites the click.'],
        'meta_description_length' => ['warning', 'Meta description too short or too long', 'Keep the meta description between 70 and 160 characters.'],
        'duplicate_meta_description' => ['warning', 'Duplicate meta description', 'Write a unique meta description for each page.'],
        'missing_h1' => ['warning', 'Missing H1 heading', 'Add one H1 that states the main topic of the page.'],
        'multiple_h1' => ['warning', 'More than one H1', 'Use a single H1 and H2/H3 for sub-sections.'],
        'thin_content' => ['warning', 'Thin content (under 300 words)', 'Expand the page with useful, original content.'],
        'images_missing_alt' => ['warning', 'Images without alt text', 'Add descriptive alt text to meaningful images (empty alt="" for decorative ones).'],
        'slow_response' => ['warning', 'Slow server response (over 1.5 s)', 'Enable caching, optimise database queries or upgrade hosting.'],
        'large_html' => ['warning', 'Large HTML document (over 500 KB)', 'Remove inline bloat; move large scripts/styles to cached files.'],
        'missing_canonical' => ['warning', 'No canonical tag', 'Add <link rel="canonical"> pointing to the preferred URL.'],
        'internal_redirect' => ['warning', 'Internal link points to a redirect', 'Link directly to the final URL to save a redirect hop.'],
        'redirect_chain' => ['warning', 'Redirect chain (2+ hops)', 'Redirect straight to the final URL in one step.'],
        'missing_viewport' => ['warning', 'No mobile viewport meta tag', 'Add <meta name="viewport" content="width=device-width, initial-scale=1">.'],
        // Notices
        'canonical_elsewhere' => ['notice', 'Canonical points to a different URL', 'Confirm this page is meant to be a duplicate of the canonical URL.'],
        'missing_lang' => ['notice', 'No lang attribute on <html>', 'Add lang="en" (or the page language) to the <html> tag.'],
        'missing_structured_data' => ['notice', 'No structured data (JSON-LD)', 'Add schema.org markup (Organization, LocalBusiness, Article, FAQ…).'],
        'missing_open_graph' => ['notice', 'No Open Graph tags', 'Add og:title, og:description and og:image for better social previews.'],
        'too_many_links' => ['notice', 'More than 150 links on the page', 'Reduce navigation clutter so important links stand out.'],
        'url_format' => ['notice', 'URL has uppercase letters, underscores or is very long', 'Prefer short, lowercase, hyphenated URLs.'],
        'deep_page' => ['notice', 'Page is more than 3 clicks from the start page', 'Link important pages closer to the homepage.'],
        'not_in_sitemap' => ['notice', 'Indexable page missing from sitemap.xml', 'Add every indexable page to the XML sitemap.'],
        'non_html' => ['notice', 'Linked resource is not an HTML page', 'Informational: PDFs and files are not analysed for on-page SEO.'],
    ];

    /** Site-wide checks (stored on the audit, not on pages). */
    public const SITE_ISSUES = [
        'no_https' => ['error', 'Site is not served over HTTPS', 'Install an SSL certificate and redirect all traffic to https://.'],
        'no_https_redirect' => ['error', 'http:// does not redirect to https://', 'Add a permanent 301 redirect from http to https.'],
        'robots_blocks_all' => ['error', 'robots.txt blocks the whole site', 'Remove "Disallow: /" for User-agent: * unless the site is private.'],
        'no_robots' => ['warning', 'No robots.txt', 'Create /robots.txt and reference your sitemap in it.'],
        'no_sitemap' => ['warning', 'No XML sitemap found', 'Publish /sitemap.xml listing every indexable page and submit it in Search Console.'],
        'sitemap_not_in_robots' => ['notice', 'Sitemap not referenced in robots.txt', 'Add "Sitemap: https://your-site/sitemap.xml" to robots.txt.'],
        'host_not_consolidated' => ['warning', 'www and non-www versions are not consolidated', 'Redirect one host version to the other with a 301.'],
        'slow_homepage' => ['warning', 'Homepage responds slowly (over 1.5 s)', 'Improve server response time (caching, CDN, hosting).'],
    ];

    public const WEIGHTS = ['error' => 10, 'warning' => 4, 'notice' => 1];

    /**
     * @return array{metrics:array<string,mixed>, issues:string[], links:string[]}
     */
    public static function analyze(HttpResponse $r, string $requestedUrl, string $siteHost, int $depth): array
    {
        $m = [
            'http_status' => $r->status ?: null,
            'final_url' => $r->finalUrl,
            'redirect_count' => count($r->redirects),
            'content_type' => $r->contentType() ?: null,
            'is_html' => 0,
            'response_ms' => $r->timeMs ?: null,
            'size_bytes' => strlen($r->body) ?: null,
            'error' => $r->error,
        ];
        $issues = [];
        $links = [];
        if ($r->errorType === HttpResponse::ERR_CONTENT_TYPE) {
            $issues[] = 'non_html';
            $m['error'] = null;
            return ['metrics' => $m, 'issues' => $issues, 'links' => []];
        }
        if ($r->errorType !== null) {
            $issues[] = 'fetch_failed';
            return ['metrics' => $m, 'issues' => $issues, 'links' => []];
        }
        if ($r->status >= 500) {
            $issues[] = 'http_5xx';
        } elseif ($r->status >= 400) {
            $issues[] = 'http_4xx';
        }
        if (count($r->redirects) >= 2) {
            $issues[] = 'redirect_chain';
        }
        if (self::badUrlFormat($requestedUrl)) {
            $issues[] = 'url_format';
        }
        if ($depth > 3) {
            $issues[] = 'deep_page';
        }
        if ($r->status >= 400 || !$r->isHtml() || $r->body === '') {
            return ['metrics' => $m, 'issues' => $issues, 'links' => []];
        }

        $m['is_html'] = 1;
        $p = HtmlParser::parse($r->body, $r->finalUrl);
        $x = self::extras($r->body);
        $title = (string)($p['title'] ?? '');
        $desc = (string)($x['meta_description'] ?? '');
        $m += [
            'title' => $title !== '' ? mb_substr($title, 0, 500) : null,
            'meta_description' => $desc !== '' ? mb_substr($desc, 0, 1000) : null,
            'h1' => $p['h1'] !== null && $p['h1'] !== '' ? mb_substr($p['h1'], 0, 300) : null,
            'h1_count' => $x['h1_count'],
            'word_count' => $p['word_count'],
            'canonical' => $p['canonical'],
            'robots_meta' => $p['robots_meta'],
            'internal_links' => $p['internal_count'],
            'external_links' => $p['outbound_count'],
            'images' => $x['images'],
            'images_missing_alt' => $x['images_missing_alt'],
        ];

        if ($title === '') {
            $issues[] = 'missing_title';
        } elseif (mb_strlen($title) > 60) {
            $issues[] = 'title_too_long';
        } elseif (mb_strlen($title) < 15) {
            $issues[] = 'title_too_short';
        }
        if ($desc === '') {
            $issues[] = 'missing_meta_description';
        } elseif (mb_strlen($desc) < 70 || mb_strlen($desc) > 160) {
            $issues[] = 'meta_description_length';
        }
        if ($x['h1_count'] === 0) {
            $issues[] = 'missing_h1';
        } elseif ($x['h1_count'] > 1) {
            $issues[] = 'multiple_h1';
        }
        if ($p['word_count'] < 300) {
            $issues[] = 'thin_content';
        }
        if ($x['images_missing_alt'] > 0) {
            $issues[] = 'images_missing_alt';
        }
        if ($r->timeMs > 1500) {
            $issues[] = 'slow_response';
        }
        if (strlen($r->body) > 500_000) {
            $issues[] = 'large_html';
        }
        if ($p['canonical'] === null) {
            $issues[] = 'missing_canonical';
        } elseif (!Url::sameResource($p['canonical'], $r->finalUrl)) {
            $issues[] = 'canonical_elsewhere';
        }
        if (str_contains((string)$p['robots_meta'], 'noindex') || str_contains(strtolower($r->headers['x-robots-tag'] ?? ''), 'noindex')) {
            $issues[] = 'noindex';
        }
        if (!$x['viewport']) {
            $issues[] = 'missing_viewport';
        }
        if (($p['lang'] ?? '') === '') {
            $issues[] = 'missing_lang';
        }
        if (!$x['json_ld']) {
            $issues[] = 'missing_structured_data';
        }
        if (!$x['open_graph']) {
            $issues[] = 'missing_open_graph';
        }
        if (count($p['links']) > 150) {
            $issues[] = 'too_many_links';
        }
        if (str_starts_with($r->finalUrl, 'https://') && $x['insecure_resources'] > 0) {
            $issues[] = 'mixed_content';
        }

        foreach ($p['links'] as $l) {
            $n = Url::normalize(explode('#', $l['href'], 2)[0]);
            if ($n !== null && self::sameSite((string)Url::host($n), $siteHost) && !preg_match('/\.(jpe?g|png|gif|webp|svg|ico|css|js|zip|rar|mp4|mp3|woff2?)(\?|$)/i', $n)) {
                $links[$n] = true;
            }
            if (count($links) >= 300) {
                break;
            }
        }
        return ['metrics' => $m, 'issues' => array_values(array_unique($issues)), 'links' => array_keys($links)];
    }

    public static function sameSite(string $host, string $siteHost): bool
    {
        $strip = static fn(string $h): string => preg_replace('/^www\./', '', strtolower($h)) ?? $h;
        return $host !== '' && $strip($host) === $strip($siteHost);
    }

    public static function badUrlFormat(string $url): bool
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        return strlen($url) > 115 || str_contains($path, '_') || preg_match('/[A-Z]/', $path) === 1;
    }

    /** @return array{h1_count:int, images:int, images_missing_alt:int, viewport:bool, json_ld:bool, open_graph:bool, insecure_resources:int, meta_description:?string} */
    public static function extras(string $html): array
    {
        $out = ['h1_count' => 0, 'images' => 0, 'images_missing_alt' => 0, 'viewport' => false, 'json_ld' => false, 'open_graph' => false, 'insecure_resources' => 0, 'meta_description' => null];
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . substr($html, 0, 3_000_000), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($doc);
        $out['h1_count'] = $xp->query('//h1')->length;
        foreach ($xp->query('//img') as $img) {
            $out['images']++;
            if ($img instanceof \DOMElement && !$img->hasAttribute('alt')) {
                $out['images_missing_alt']++;
            }
        }
        foreach ($xp->query('//meta') as $meta) {
            if (!$meta instanceof \DOMElement) {
                continue;
            }
            $name = strtolower($meta->getAttribute('name') ?: $meta->getAttribute('property'));
            if ($name === 'viewport') {
                $out['viewport'] = true;
            } elseif (str_starts_with($name, 'og:')) {
                $out['open_graph'] = true;
            } elseif ($name === 'description' && $out['meta_description'] === null) {
                $out['meta_description'] = trim(preg_replace('/\s+/u', ' ', $meta->getAttribute('content')) ?? '');
            }
        }
        $out['json_ld'] = $xp->query('//script[@type="application/ld+json"]')->length > 0;
        foreach ($xp->query('//img[@src]|//script[@src]|//iframe[@src]|//link[@rel="stylesheet"][@href]|//source[@src]') as $el) {
            if ($el instanceof \DOMElement && preg_match('#^http://#i', trim($el->getAttribute('src') ?: $el->getAttribute('href')))) {
                $out['insecure_resources']++;
            }
        }
        return $out;
    }

    /**
     * Transparent score: start at 100 and subtract, per issue type, its severity weight
     * multiplied by the share of pages affected (site-wide issues count as 100%).
     * @param array<string,int> $pageIssueCounts code => pages affected
     * @param string[] $siteIssues
     */
    public static function score(array $pageIssueCounts, array $siteIssues, int $pages): int
    {
        $penalty = 0.0;
        $pages = max(1, $pages);
        foreach ($pageIssueCounts as $code => $n) {
            $sev = self::ISSUES[$code][0] ?? 'notice';
            $penalty += self::WEIGHTS[$sev] * min(1.0, 0.25 + 0.75 * $n / $pages);
        }
        foreach ($siteIssues as $code) {
            $penalty += self::WEIGHTS[self::SITE_ISSUES[$code][0] ?? 'notice'];
        }
        return (int)max(0, min(100, round(100 - $penalty)));
    }
}
