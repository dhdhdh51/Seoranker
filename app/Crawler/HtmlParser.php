<?php
declare(strict_types=1);

namespace BharatSeo\Crawler;

/**
 * Parses fetched HTML with DOMDocument (never executes content, no network access)
 * and extracts metadata, links (with anchor text and rel attributes), text statistics
 * and publicly listed contact details.
 */
final class HtmlParser
{
    /**
     * @return array{
     *   title:?string, description:?string, canonical:?string, site_name:?string, robots_meta:?string, lang:?string,
     *   links: array<int,array{href:string, anchor:string, rel:string[], nofollow:bool, sponsored:bool, ugc:bool, hidden:bool, external:bool, in_content:bool}>,
     *   text:string, word_count:int, outbound_count:int, internal_count:int, emails:string[], contact_pages:string[],
     *   has_author:bool, has_date:bool, h1:?string
     * }
     */
    public static function parse(string $html, string $baseUrl): array
    {
        $result = [
            'title' => null, 'description' => null, 'canonical' => null, 'site_name' => null, 'robots_meta' => null, 'lang' => null,
            'links' => [], 'text' => '', 'word_count' => 0, 'outbound_count' => 0, 'internal_count' => 0,
            'emails' => [], 'contact_pages' => [], 'has_author' => false, 'has_date' => false, 'h1' => null,
        ];
        $html = substr($html, 0, 3_000_000);
        if (trim($html) === '') {
            return $result;
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($doc);

        // <base href> support (only http/https)
        $base = $baseUrl;
        $baseEl = $xp->query('//base[@href]')->item(0);
        if ($baseEl instanceof \DOMElement) {
            $resolved = Url::resolve($baseUrl, $baseEl->getAttribute('href'));
            if ($resolved !== null) {
                $base = $resolved;
            }
        }

        $title = $xp->query('//title')->item(0);
        $result['title'] = $title ? self::clean($title->textContent, 500) : null;
        $html0 = $xp->query('//html')->item(0);
        $result['lang'] = $html0 instanceof \DOMElement && $html0->hasAttribute('lang') ? self::clean($html0->getAttribute('lang'), 20) : null;
        foreach ($xp->query('//meta') as $m) {
            if (!$m instanceof \DOMElement) {
                continue;
            }
            $name = strtolower($m->getAttribute('name') ?: $m->getAttribute('property'));
            $content = self::clean($m->getAttribute('content'), 1000);
            if ($content === '') {
                continue;
            }
            match ($name) {
                'description' => $result['description'] ??= $content,
                'og:description' => $result['description'] ??= $content,
                'og:site_name' => $result['site_name'] ??= $content,
                'robots' => $result['robots_meta'] ??= strtolower($content),
                'author', 'article:author' => $result['has_author'] = true,
                'article:published_time', 'date', 'pubdate' => $result['has_date'] = true,
                default => null,
            };
        }
        foreach ($xp->query('//link[@rel]') as $l) {
            if ($l instanceof \DOMElement && in_array('canonical', preg_split('/\s+/', strtolower($l->getAttribute('rel'))) ?: [], true)) {
                $result['canonical'] = Url::resolve($base, $l->getAttribute('href'));
                break;
            }
        }
        $h1 = $xp->query('//h1')->item(0);
        $result['h1'] = $h1 ? self::clean($h1->textContent, 300) : null;
        if ($xp->query('//*[@rel="author" or contains(@class,"author") or @itemprop="author"]')->length > 0) {
            $result['has_author'] = true;
        }
        if ($xp->query('//time[@datetime] | //*[@itemprop="datePublished"]')->length > 0) {
            $result['has_date'] = true;
        }

        // Visible text (strip scripts, styles, templates, noscript).
        foreach (iterator_to_array($xp->query('//script|//style|//noscript|//template|//svg')) as $n) {
            $n->parentNode?->removeChild($n);
        }
        $body = $xp->query('//body')->item(0) ?? $doc->documentElement;
        $text = $body ? self::clean($body->textContent, 2_000_000) : '';
        $result['text'] = mb_substr($text, 0, 200_000);
        $result['word_count'] = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);

        $pageHost = Url::host($baseUrl) ?? '';
        $pageSite = Url::registrableDomain($pageHost);
        $emails = [];
        $contactPages = [];
        foreach ($xp->query('//a[@href]') as $a) {
            if (!$a instanceof \DOMElement) {
                continue;
            }
            $hrefRaw = trim($a->getAttribute('href'));
            if (stripos($hrefRaw, 'mailto:') === 0) {
                $addr = strtolower(trim(rawurldecode(explode('?', substr($hrefRaw, 7), 2)[0])));
                if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $emails[$addr] = true;
                }
                continue;
            }
            $href = Url::resolve($base, $hrefRaw);
            if ($href === null) {
                continue;
            }
            $anchor = self::clean($a->textContent, 500);
            if ($anchor === '') {
                foreach ($a->getElementsByTagName('img') as $img) {
                    $anchor = self::clean($img->getAttribute('alt'), 500);
                    if ($anchor !== '') {
                        $anchor = '[img] ' . $anchor;
                        break;
                    }
                }
            }
            $rel = array_values(array_filter(preg_split('/\s+/', strtolower(trim($a->getAttribute('rel')))) ?: []));
            $host = Url::host($href) ?? '';
            $external = $host !== '' && Url::registrableDomain($host) !== $pageSite;
            $result['links'][] = [
                'href' => $href,
                'anchor' => $anchor,
                'rel' => $rel,
                'nofollow' => in_array('nofollow', $rel, true),
                'sponsored' => in_array('sponsored', $rel, true),
                'ugc' => in_array('ugc', $rel, true),
                'hidden' => self::isHidden($a),
                'external' => $external,
                'in_content' => self::inContent($a),
            ];
            if ($external) {
                $result['outbound_count']++;
            } else {
                $result['internal_count']++;
                if (preg_match('#(contact|write-for-us|contribute|guest-post|submission|submit|advertise|guidelines)#i', $href . ' ' . $anchor)) {
                    $contactPages[$href] = true;
                }
            }
            if (count($result['links']) >= 2000) {
                break;
            }
        }
        if (preg_match_all('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}\b/i', mb_substr($text, 0, 100000), $m)) {
            foreach ($m[0] as $e) {
                $e = strtolower($e);
                if (filter_var($e, FILTER_VALIDATE_EMAIL) && !preg_match('/\.(png|jpe?g|gif|webp|svg)$/', $e)) {
                    $emails[$e] = true;
                }
            }
        }
        $result['emails'] = array_slice(array_keys($emails), 0, 10);
        $result['contact_pages'] = array_slice(array_keys($contactPages), 0, 10);
        return $result;
    }

    /** Detect inline-hidden links (element or ancestors hidden via attributes/inline CSS). */
    private static function isHidden(\DOMElement $el): bool
    {
        $node = $el;
        $depth = 0;
        while ($node instanceof \DOMElement && $depth < 25) {
            if ($node->hasAttribute('hidden') || strtolower($node->getAttribute('aria-hidden')) === 'true') {
                return true;
            }
            $style = strtolower(str_replace(' ', '', $node->getAttribute('style')));
            if ($style !== '' && preg_match('/display:none|visibility:hidden|font-size:0(px|em|rem|pt|%)?(;|$)|opacity:0(;|$)|text-indent:-\d{3,}|left:-\d{3,}px|height:0(px)?;.*overflow:hidden|width:0(px)?;.*overflow:hidden/', $style)) {
                return true;
            }
            $node = $node->parentNode;
            $depth++;
        }
        return false;
    }

    /** Heuristic: link inside main content rather than nav/header/footer/aside. */
    private static function inContent(\DOMElement $el): bool
    {
        $node = $el->parentNode;
        $depth = 0;
        while ($node instanceof \DOMElement && $depth < 30) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['nav', 'footer', 'header', 'aside'], true)) {
                return false;
            }
            $cls = strtolower($node->getAttribute('class') . ' ' . $node->getAttribute('id'));
            if (preg_match('/\b(footer|sidebar|widget|menu|navbar|nav|breadcrumb|comments?)\b/', $cls)) {
                return false;
            }
            if (in_array($tag, ['article', 'main'], true) || preg_match('/\b(content|entry|post|article)\b/', $cls)) {
                return true;
            }
            $node = $node->parentNode;
            $depth++;
        }
        return true;
    }

    public static function clean(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return mb_substr($s, 0, $max);
    }

    /**
     * Find links pointing at $domain (and optionally a specific target URL).
     * @return array<int,array> matching links
     */
    public static function linksTo(array $parsed, string $domain, ?string $targetUrl = null): array
    {
        $out = [];
        foreach ($parsed['links'] as $l) {
            if (!Url::belongsToDomain($l['href'], $domain)) {
                continue;
            }
            $l['matches_target'] = $targetUrl === null || Url::sameResource($l['href'], $targetUrl);
            $out[] = $l;
        }
        return $out;
    }
}
