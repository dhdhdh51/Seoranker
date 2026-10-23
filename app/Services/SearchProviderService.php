<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Jobs\PermanentException;
use BharatSeo\Jobs\RetryableException;
use BharatSeo\Security\RateLimiter;

/**
 * Optional, legitimate search-provider APIs (never scraping search result pages):
 *  - Google Custom Search JSON API (key + cx) — closed to new customers; Google discontinues it 1 Jan 2027
 *  - Brave Search API (subscription token)
 *  - SearXNG instance you operate or are authorized to use (JSON format enabled)
 * A configurable daily quota protects free tiers.
 */
class SearchProviderService
{
    public function __construct(protected App $app)
    {
    }

    public function provider(): string
    {
        return (string)$this->app->settings()->get('search.provider', 'none');
    }

    public function providerName(): string
    {
        return match ($this->provider()) {
            'google_cse' => 'Google Custom Search JSON API (legacy; Google discontinues it on 1 Jan 2027)',
            'brave' => 'Brave Search API',
            'searxng' => 'SearXNG',
            default => 'Not configured',
        };
    }

    public function isAvailable(): bool
    {
        $s = $this->app->settings();
        if (!$s->moduleEnabled('search_api')) {
            return false;
        }
        return match ($this->provider()) {
            'google_cse' => $s->has('search.api_key') && $s->has('search.cse_id'),
            'brave' => $s->has('search.api_key'),
            'searxng' => $s->has('search.searxng_url'),
            default => false,
        };
    }

    /** Footprint queries for an opportunity category. */
    public static function footprints(array $project, array $keywords, string $category): array
    {
        $kw = array_slice(array_values(array_filter($keywords)), 0, 4);
        if ($kw === [] && !empty($project['niche'])) {
            $kw = [(string)$project['niche']];
        }
        $out = [];
        $exclude = ' -site:' . $project['domain'];
        foreach ($kw as $k) {
            $q = '"' . str_replace('"', '', $k) . '"';
            $out[] = match ($category) {
                'resource_page' => "$q intitle:resources OR intitle:\"useful links\"$exclude",
                'guest_post', 'expert_contribution' => "$q \"write for us\" OR \"contributor guidelines\"$exclude",
                'business_directory', 'local_citation' => "$q directory \"add your business\" OR \"submit listing\"$exclude",
                'industry_association' => "$q association members directory$exclude",
                'partner_page' => "$q \"our partners\" OR \"suppliers\"$exclude",
                'community_profile' => "$q community professionals profile$exclude",
                default => "$q$exclude",
            };
        }
        if ($category === 'unlinked_mention' && !empty($project['brand_name'])) {
            $out = ['"' . str_replace('"', '', (string)$project['brand_name']) . '"' . $exclude];
        }
        return array_values(array_unique($out));
    }

    /**
     * Run one query. Returns up to N result rows [url, title, snippet].
     * @return array<int,array{url:string,title:string,snippet:string}>
     */
    public function search(string $query): array
    {
        if (!$this->isAvailable()) {
            throw new PermanentException('Search provider not configured.');
        }
        $quota = (int)$this->app->settings()->get('search.daily_quota', 90);
        if (!$this->app->make(RateLimiter::class)->hit('search:daily:' . gmdate('Y-m-d'), $quota, 86400)) {
            throw new RetryableException('Daily search API quota reached; will continue tomorrow.', 86400 - (time() % 86400) + 120);
        }
        $s = $this->app->settings();
        $n = (int)$s->get('discovery.max_results_per_query', 10);
        [$url, $headers] = match ($this->provider()) {
            'google_cse' => ['https://www.googleapis.com/customsearch/v1?' . http_build_query(['key' => $s->get('search.api_key'), 'cx' => $s->get('search.cse_id'), 'q' => $query, 'num' => min(10, $n)]), []],
            'brave' => ['https://api.search.brave.com/res/v1/web/search?' . http_build_query(['q' => $query, 'count' => min(20, $n)]), ['Accept: application/json', 'X-Subscription-Token: ' . $s->get('search.api_key')]],
            'searxng' => [rtrim((string)$s->get('search.searxng_url'), '/') . '/search?' . http_build_query(['q' => $query, 'format' => 'json']), ['Accept: application/json']],
            default => throw new PermanentException('Unknown provider'),
        };
        [$status, $body] = $this->httpGetJson($url, $headers);
        if ($status === 429 || $status >= 500 || $status === 0) {
            throw new RetryableException('Search provider temporarily unavailable (HTTP ' . $status . ').');
        }
        if ($status !== 200) {
            throw new PermanentException('Search provider rejected the request (HTTP ' . $status . '). Check the API key and quota.');
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RetryableException('Search provider returned invalid JSON.');
        }
        $items = match ($this->provider()) {
            'google_cse' => array_map(static fn($i) => ['url' => (string)($i['link'] ?? ''), 'title' => (string)($i['title'] ?? ''), 'snippet' => (string)($i['snippet'] ?? '')], $json['items'] ?? []),
            'brave' => array_map(static fn($i) => ['url' => (string)($i['url'] ?? ''), 'title' => (string)($i['title'] ?? ''), 'snippet' => strip_tags((string)($i['description'] ?? ''))], $json['web']['results'] ?? []),
            'searxng' => array_map(static fn($i) => ['url' => (string)($i['url'] ?? ''), 'title' => (string)($i['title'] ?? ''), 'snippet' => (string)($i['content'] ?? '')], $json['results'] ?? []),
            default => [],
        };
        return array_slice(array_values(array_filter($items, static fn($i) => preg_match('#^https?://#i', $i['url']))), 0, $n);
    }

    /** @return array{0:int,1:string} */
    protected function httpGetJson(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'BharatSEO/' . APP_VERSION,
            CURLOPT_MAXFILESIZE => 2_000_000,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, is_string($body) ? $body : ''];
    }
}
