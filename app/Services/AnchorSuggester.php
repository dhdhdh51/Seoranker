<?php
declare(strict_types=1);

namespace BharatSeo\Services;

/**
 * Suggests natural anchor text (brand, URL, descriptive phrases). Exact-match
 * commercial keyword anchors are deliberately avoided.
 */
final class AnchorSuggester
{
    /** @param string[] $keywords */
    public static function suggest(array $project, ?array $targetPage, array $keywords, string $category, int $seed = 0): string
    {
        $candidates = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)($project['preferred_anchors'] ?? '')) ?: [] as $a) {
            $a = trim($a);
            if ($a !== '' && !QualityScorer::isUnnaturalAnchor($a, $keywords)) {
                $candidates[] = $a;
            }
        }
        $brand = trim((string)($project['brand_name'] ?? ''));
        $domain = (string)($project['domain'] ?? '');
        if ($category === 'unlinked_mention' && $brand !== '') {
            return $brand; // link the existing mention itself
        }
        if (in_array($category, ['business_directory', 'local_citation', 'community_profile', 'industry_association'], true)) {
            return $brand !== '' ? $brand : $domain;
        }
        if ($brand !== '') {
            $candidates[] = $brand;
        }
        if ($domain !== '') {
            $candidates[] = $domain;
        }
        if ($targetPage && !empty($targetPage['title'])) {
            $t = trim(preg_split('/\s[|\-–—]\s/u', (string)$targetPage['title'])[0] ?? '');
            if ($t !== '' && mb_strlen($t) <= 60 && !QualityScorer::isUnnaturalAnchor($t, $keywords)) {
                $candidates[] = $t;
            }
        }
        if ($candidates === []) {
            return 'this resource';
        }
        $candidates = array_values(array_unique($candidates));
        return $candidates[$seed % count($candidates)];
    }
}
