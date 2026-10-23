<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Crawler\Url;

/**
 * Transparent internal quality / spam-risk heuristic (0–100).
 *
 * This is NOT PageRank, Domain Authority, Domain Rating or a ranking guarantee.
 * Missing evidence is reported as "Unknown" and earns no points — it is never
 * treated as proof of quality. Critical risk flags force a manual hold.
 */
final class QualityScorer
{
    public const VERSION = '1.0';

    public const WEIGHTS = [
        'relevance' => ['label' => 'Topical relevance', 'max' => 30],
        'editorial' => ['label' => 'Editorial quality', 'max' => 25],
        'trust' => ['label' => 'Domain & website trust indicators', 'max' => 20],
        'technical' => ['label' => 'Page accessibility & technical health', 'max' => 15],
        'placement' => ['label' => 'Link placement & context', 'max' => 10],
    ];

    public const SPAM_TERMS = [
        'casino', 'betting', 'poker', 'viagra', 'cialis', 'payday loan', 'escort', 'porn', 'xxx', 'replica watches',
        'essay writing service', 'buy followers', 'cheap backlinks', 'pbn', 'satta', 'crypto giveaway', 'adult dating',
    ];

    private const COMMERCIAL_ANCHOR = '/\b(best|cheap|buy|top|price|discount|deal|near me|online|services?|agency|company)\b/i';

    /**
     * @param array $s signals (see EvaluationService::signals)
     * @return array{total:int, band:string, components:array, flags:array, has_critical:bool, version:string}
     */
    public function score(array $s): array
    {
        $flags = [];
        $parsed = $s['parsed'] ?? null;
        $fetched = !empty($s['fetched']) && is_array($parsed);
        $category = (string)($s['category'] ?? 'other');
        $text = $fetched ? mb_strtolower((string)$parsed['text']) : '';

        $components = [
            'relevance' => $this->relevance($s, $fetched, $text, $flags),
            'editorial' => $this->editorial($s, $fetched, $text, $category, $flags),
            'trust' => $this->trust($s, $fetched, $text, $flags),
            'technical' => $this->technical($s, $flags),
            'placement' => $this->placement($s, $fetched, $category, $flags),
        ];

        // Cross-cutting flags
        if (!empty($s['excluded'])) {
            $flags[] = self::flag('excluded_domain', 'critical', 'Domain is on the project exclusion list or the global blocklist.');
        }
        if ((int)($s['domain_page_count'] ?? 0) > 1) {
            $flags[] = self::flag('repeated_domain', 'info', 'This domain already has ' . ((int)$s['domain_page_count'] - 1) . ' other prospect page(s) in this project. Contact the domain once.');
        }
        if (!empty($s['own_domain'])) {
            $flags[] = self::flag('own_domain', 'critical', 'This page belongs to the project\'s own website.');
        }
        if ($fetched && preg_match('/\b(sponsored post|paid post|paid guest post|guest post (price|fee|rates?)|advertise with us|link insertion|pay for (a )?(guest post|link)|price list|our rates)\b/i', $text)) {
            $flags[] = self::flag('paid_placement', 'warning', 'Paid placement indicators found. Any paid link must be qualified with rel="sponsored" (or nofollow); never buy links that pass ranking signals.');
        }
        $anchor = trim((string)($s['suggested_anchor'] ?? ''));
        if ($anchor !== '' && self::isUnnaturalAnchor($anchor, (array)($s['keywords'] ?? []))) {
            $flags[] = self::flag('unnatural_anchor', 'warning', 'Suggested anchor looks keyword-heavy or commercial. Prefer brand, URL or descriptive natural anchors.');
        }

        $total = 0;
        foreach ($components as $c) {
            $total += $c['score'];
        }
        $total = max(0, min(100, $total));
        $hasCritical = (bool)array_filter($flags, static fn($f) => $f['severity'] === 'critical');

        return [
            'total' => $total,
            'band' => self::band($total),
            'components' => $components,
            'flags' => array_values($flags),
            'has_critical' => $hasCritical,
            'version' => self::VERSION,
        ];
    }

    public static function band(int $score): string
    {
        return $score >= 80 ? 'high' : ($score >= 60 ? 'review' : 'low');
    }

    public static function bandLabel(string $band): string
    {
        return match ($band) {
            'high' => '80–100 · High-priority manual review',
            'review' => '60–79 · Review recommended',
            default => 'Below 60 · Low priority or reject',
        };
    }

    private function relevance(array $s, bool $fetched, string $text, array &$flags): array
    {
        $max = 30;
        $terms = self::terms($s);
        if ($terms === []) {
            return self::unknown('relevance', 'Unknown — no project keywords, topics or niche configured.');
        }
        if (!$fetched) {
            return self::unknown('relevance', 'Unknown — page content could not be fetched.');
        }
        $p = $s['parsed'];
        $title = mb_strtolower((string)($p['title'] ?? ''));
        $meta = mb_strtolower(($p['description'] ?? '') . ' ' . ($p['h1'] ?? ''));
        $notes = [];
        $score = 0;

        $titleHit = self::firstHit($terms, $title);
        if ($titleHit !== null) {
            $score += 10;
            $notes[] = "Title mentions “{$titleHit}” (+10).";
        } elseif (self::wordOverlap($terms, $title) > 0) {
            $score += 5;
            $notes[] = 'Title shares words with project topics (+5).';
        } else {
            $notes[] = 'Title does not mention project topics (+0).';
        }
        $metaHit = self::firstHit($terms, $meta);
        if ($metaHit !== null) {
            $score += 5;
            $notes[] = "Description/H1 mentions “{$metaHit}” (+5).";
        }
        $present = 0;
        $maxFreq = 0;
        foreach ($terms as $t) {
            $c = substr_count($text, $t);
            if ($c > 0) {
                $present++;
            }
            $maxFreq = max($maxFreq, $c);
        }
        $coverage = $present / count($terms);
        $cov = (int)round(10 * min(1.0, $coverage * 2));
        $score += $cov;
        $notes[] = sprintf('Body text covers %d of %d project terms (+%d).', $present, count($terms), $cov);
        if ($maxFreq >= 3) {
            $score += 5;
            $notes[] = 'Topic discussed repeatedly in the content (+5).';
        }
        $score = min($max, $score);
        if ($score < 8) {
            $flags[] = self::flag('irrelevant', 'warning', 'Low topical relevance to the project niche and keywords.');
        }
        return self::component('relevance', $score, 'scored', $notes);
    }

    private function editorial(array $s, bool $fetched, string $text, string $category, array &$flags): array
    {
        if (!$fetched) {
            return self::unknown('editorial', 'Unknown — page content could not be fetched.');
        }
        $p = $s['parsed'];
        $notes = [];
        $score = 0;
        $wc = (int)$p['word_count'];
        $wcPts = $wc >= 1200 ? 10 : ($wc >= 600 ? 8 : ($wc >= 300 ? 5 : ($wc >= 150 ? 2 : 0)));
        $score += $wcPts;
        $notes[] = "About $wc words of visible text (+$wcPts).";
        if ($wc < 150) {
            $flags[] = self::flag('thin_content', 'warning', 'Very little visible content (thin page).');
        }
        if (!empty($p['has_author'])) {
            $score += 4;
            $notes[] = 'Author attribution found (+4).';
        } else {
            $notes[] = 'No author attribution detected (+0).';
        }
        if (!empty($p['has_date'])) {
            $score += 3;
            $notes[] = 'Publication/update date found (+3).';
        }
        $uniq = self::sentenceUniqueness($text);
        if ($uniq === null) {
            $notes[] = 'Content uniqueness: Unknown (too little text).';
        } elseif ($uniq >= 0.8) {
            $score += 4;
            $notes[] = 'Sentences are mostly unique (+4).';
        } elseif ($uniq >= 0.6) {
            $score += 2;
            $notes[] = 'Some repeated sentences (+2).';
        } else {
            $notes[] = 'Many repeated sentences (+0).';
            $flags[] = self::flag('scraped_content', 'warning', 'Highly repetitive text — possible scraped, spun or template content.');
        }
        if (str_contains($text, 'lorem ipsum')) {
            $flags[] = self::flag('placeholder_content', 'warning', 'Placeholder “lorem ipsum” text found.');
        }
        $hasGuidelines = (bool)preg_match('/\b(write for us|guest post guidelines|submission guidelines|editorial guidelines|contributor guidelines|submit (a|your) (post|article|guest post)|become a contributor|contribute to)\b/i', $text)
            || array_filter((array)($p['contact_pages'] ?? []), static fn($u) => preg_match('#write-for-us|guidelines|contribute|submission#i', $u));
        if (in_array($category, ['guest_post', 'expert_contribution'], true)) {
            if ($hasGuidelines) {
                $score += 4;
                $notes[] = 'Editorial / submission guidelines found (+4).';
            } else {
                $flags[] = self::flag('missing_guidelines', 'warning', 'No editorial or submission guidelines detected. Confirm the site accepts contributions before pitching.');
                $notes[] = 'Editorial guidelines: Unknown / not found (+0).';
            }
        } elseif (!empty($p['h1']) && !empty($p['description'])) {
            $score += 4;
            $notes[] = 'Structured page (H1 and description present) (+4).';
        }
        return self::component('editorial', min(25, $score), 'scored', $notes);
    }

    private function trust(array $s, bool $fetched, string $text, array &$flags): array
    {
        $notes = [];
        $score = 0;
        $status = 'scored';
        $url = (string)($s['final_url'] ?? $s['url'] ?? '');
        if (str_starts_with($url, 'https://')) {
            $score += 4;
            $notes[] = 'Served over HTTPS (+4).';
        } else {
            $notes[] = 'Not served over HTTPS (+0).';
        }
        if ($fetched) {
            $p = $s['parsed'];
            $hasAboutContact = false;
            foreach ($p['links'] as $l) {
                if (!$l['external'] && preg_match('#/(about|contact|team|company|imprint|privacy)#i', (string)parse_url($l['href'], PHP_URL_PATH))) {
                    $hasAboutContact = true;
                    break;
                }
            }
            if ($hasAboutContact) {
                $score += 4;
                $notes[] = 'About/contact/privacy pages linked (+4).';
            } else {
                $notes[] = 'No about/contact pages linked (+0).';
            }
            $canonical = $p['canonical'] ?? null;
            if ($canonical === null) {
                $score += 1;
                $notes[] = 'No canonical tag (+1).';
            } elseif (Url::sameResource($canonical, $url)) {
                $score += 3;
                $notes[] = 'Canonical points to this page (+3).';
            } elseif (Url::registrableDomain($canonical) !== Url::registrableDomain($url)) {
                $notes[] = 'Canonical points to another site (+0).';
                $flags[] = self::flag('foreign_canonical', 'warning', 'Canonical URL points to a different website (possible syndicated or scraped copy).');
            } else {
                $score += 1;
                $notes[] = 'Canonical points to another URL on the same site (+1).';
            }
            $spam = self::firstHit(self::SPAM_TERMS, $text . ' ' . mb_strtolower((string)($p['title'] ?? '')));
            if ($spam === null) {
                $score += 5;
                $notes[] = 'No high-risk spam vocabulary found (+5).';
            } else {
                $notes[] = "High-risk vocabulary found: “{$spam}” (+0).";
                $flags[] = self::flag('spam_content', 'critical', "High-risk spam vocabulary detected (“{$spam}”).");
            }
        } else {
            $status = 'partial';
            $notes[] = 'Content-based trust indicators: Unknown (page not fetched).';
        }
        $sb = $s['safe_browsing'] ?? null;
        if ($sb === 'safe') {
            $score += 4;
            $notes[] = 'Safe Browsing reported no known threats (+4).';
        } elseif ($sb === 'unsafe') {
            $notes[] = 'Safe Browsing reported a threat (+0).';
            $flags[] = self::flag('malware_suspected', 'critical', 'Unsafe-site indicator reported by Safe Browsing.');
        } else {
            $status = 'partial';
            $notes[] = 'Malware/reputation check: Unknown (no reputation service configured or check failed).';
        }
        if (!empty($s['blocklisted'])) {
            $flags[] = self::flag('blocklisted', 'critical', 'Domain is on the administrator blocklist.');
        }
        $notes[] = 'Domain age and third-party authority metrics: Unknown (not collected; no paid SEO API).';
        return self::component('trust', min(20, $score), $status, $notes);
    }

    private function technical(array $s, array &$flags): array
    {
        $notes = [];
        $errorType = $s['error_type'] ?? null;
        if (empty($s['attempted'])) {
            return self::unknown('technical', 'Unknown — page not fetched yet.');
        }
        if ($errorType === 'robots_disallowed') {
            $flags[] = self::flag('robots_disallowed', 'warning', 'robots.txt disallows our crawler for this page; it was not fetched.');
            return self::unknown('technical', 'Unknown — robots.txt disallows fetching this page.');
        }
        if ($errorType === 'blocked_url') {
            $flags[] = self::flag('unsafe_url', 'critical', 'URL failed safety validation: ' . ($s['error'] ?? ''));
            return self::unknown('technical', 'URL rejected by the safety validator.');
        }
        $score = 0;
        $status = (int)($s['http_status'] ?? 0);
        if ($errorType !== null) {
            $flags[] = self::flag('inaccessible', 'warning', 'Page could not be fetched: ' . ($s['error'] ?? $errorType));
            $notes[] = 'Request failed (' . $errorType . ') (+0).';
        } elseif ($status === 200) {
            $score += 8;
            $notes[] = 'HTTP 200 OK (+8).';
        } elseif ($status >= 200 && $status < 300) {
            $score += 6;
            $notes[] = "HTTP $status (+6).";
        } else {
            $flags[] = self::flag('broken_page', 'warning', "Page returned HTTP $status.");
            $notes[] = "HTTP $status (+0).";
        }
        $redirects = (array)($s['redirects'] ?? []);
        $req = (string)($s['url'] ?? '');
        $final = (string)($s['final_url'] ?? $req);
        if ($redirects === []) {
            $score += 3;
            $notes[] = 'No redirects (+3).';
        } elseif (Url::registrableDomain($req) === Url::registrableDomain($final)) {
            $score += 2;
            $notes[] = count($redirects) . ' same-site redirect(s) (+2).';
        } else {
            $notes[] = 'Redirects to a different website (+0).';
            $flags[] = self::flag('suspicious_redirect', 'critical', 'Page redirects to a different domain: ' . Url::registrableDomain($final));
        }
        if ($errorType === null && !empty($s['is_html'])) {
            $score += 2;
            $notes[] = 'HTML content type (+2).';
        }
        $ms = (int)($s['response_ms'] ?? 0);
        if ($errorType === null && $ms > 0) {
            $pts = $ms < 2000 ? 2 : ($ms < 5000 ? 1 : 0);
            $score += $pts;
            $notes[] = "Response time {$ms} ms (+$pts).";
        }
        $robotsMeta = (string)($s['parsed']['robots_meta'] ?? '');
        if (str_contains($robotsMeta, 'noindex')) {
            $flags[] = self::flag('noindex', 'warning', 'Page has a noindex robots meta tag.');
        }
        return self::component('technical', min(15, $score), 'scored', $notes);
    }

    private function placement(array $s, bool $fetched, string $category, array &$flags): array
    {
        $notes = [];
        $score = 0;
        $contextual = ['resource_page', 'guest_post', 'expert_contribution', 'broken_link', 'unlinked_mention', 'partner_page', 'industry_association'];
        if (in_array($category, $contextual, true)) {
            $score += 4;
            $notes[] = 'Category supports a contextual/editorial link (+4).';
        } elseif ($category === 'other') {
            $notes[] = 'Category not specified (+0).';
        } else {
            $score += 2;
            $notes[] = 'Listing/profile-style placement (+2).';
        }
        if (!$fetched) {
            $notes[] = 'Existing link context: Unknown (page not fetched).';
            return self::component('placement', $score, 'partial', $notes);
        }
        $p = $s['parsed'];
        $external = array_values(array_filter($p['links'], static fn($l) => $l['external']));
        $inContent = array_filter($external, static fn($l) => $l['in_content'] && !$l['hidden']);
        $hidden = array_filter($external, static fn($l) => $l['hidden']);
        if ($inContent) {
            $score += 3;
            $notes[] = count($inContent) . ' outbound link(s) inside main content (+3).';
        } else {
            $score += 1;
            $notes[] = 'No outbound links in main content (+1).';
        }
        $qualified = array_filter($external, static fn($l) => $l['nofollow'] || $l['sponsored'] || $l['ugc']);
        if ($external === []) {
            $notes[] = 'Outbound link attributes: Unknown (no outbound links).';
        } elseif (count($qualified) / count($external) < 0.5) {
            $score += 3;
            $notes[] = 'Most outbound links are editorial (not nofollow/sponsored/ugc) (+3).';
        } else {
            $score += 1;
            $notes[] = 'Most outbound links are qualified (nofollow/sponsored/ugc) — a new link would likely be qualified too (+1).';
        }
        $outCount = (int)$p['outbound_count'];
        if ($outCount > 400) {
            $flags[] = self::flag('link_farm', 'critical', "Excessive outbound links ($outCount) — link-farm pattern.");
            $score = 0;
        } elseif ($outCount > 150) {
            $flags[] = self::flag('excessive_outbound', 'warning', "High number of outbound links ($outCount).");
            $score = max(0, $score - 3);
        }
        if ($hidden) {
            $flags[] = self::flag('hidden_links', 'critical', count($hidden) . ' hidden or visually concealed outbound link(s) detected.');
            $score = 0;
        }
        return self::component('placement', min(10, $score), 'scored', $notes);
    }

    // ---------------------------------------------------------------- helpers

    public static function isUnnaturalAnchor(string $anchor, array $keywords): bool
    {
        $a = mb_strtolower(trim($anchor));
        $words = count(preg_split('/\s+/u', $a) ?: []);
        if ($words > 7) {
            return true;
        }
        foreach ($keywords as $k) {
            $k = mb_strtolower(trim((string)$k));
            if ($k !== '' && $a === $k && (preg_match(self::COMMERCIAL_ANCHOR, $a) || $words >= 3)) {
                return true;
            }
        }
        return (bool)preg_match('/\b(best|cheap|buy)\b.*\b(services?|company|online|price)\b/i', $a);
    }

    private static function terms(array $s): array
    {
        $terms = [];
        foreach (array_merge((array)($s['keywords'] ?? []), (array)($s['topics'] ?? [])) as $t) {
            $t = mb_strtolower(trim((string)$t));
            if (mb_strlen($t) >= 3) {
                $terms[$t] = true;
            }
        }
        $niche = mb_strtolower(trim((string)($s['niche'] ?? '')));
        if (mb_strlen($niche) >= 3) {
            $terms[$niche] = true;
        }
        return array_slice(array_keys($terms), 0, 100);
    }

    private static function firstHit(array $terms, string $haystack): ?string
    {
        foreach ($terms as $t) {
            if ($t !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($t, '/') . '(?![\p{L}\p{N}])/u', $haystack)) {
                return $t;
            }
        }
        return null;
    }

    private static function wordOverlap(array $terms, string $haystack): int
    {
        $words = [];
        foreach ($terms as $t) {
            foreach (preg_split('/\s+/u', $t) ?: [] as $w) {
                if (mb_strlen($w) >= 4) {
                    $words[$w] = true;
                }
            }
        }
        $n = 0;
        foreach (array_keys($words) as $w) {
            if (str_contains($haystack, $w)) {
                $n++;
            }
        }
        return $n;
    }

    private static function sentenceUniqueness(string $text): ?float
    {
        $sentences = array_filter(array_map('trim', preg_split('/[.!?]+\s/u', mb_substr($text, 0, 50000)) ?: []), static fn($x) => mb_strlen($x) > 25);
        if (count($sentences) < 5) {
            return null;
        }
        return count(array_unique($sentences)) / count($sentences);
    }

    private static function component(string $key, int $score, string $status, array $notes): array
    {
        return ['key' => $key, 'label' => self::WEIGHTS[$key]['label'], 'score' => max(0, $score), 'max' => self::WEIGHTS[$key]['max'], 'status' => $status, 'notes' => $notes];
    }

    private static function unknown(string $key, string $note): array
    {
        return self::component($key, 0, 'unknown', [$note]);
    }

    private static function flag(string $code, string $severity, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => $message];
    }
}
