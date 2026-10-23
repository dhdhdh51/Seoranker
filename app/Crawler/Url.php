<?php
declare(strict_types=1);

namespace BharatSeo\Crawler;

/**
 * URL normalization, resolution and domain helpers.
 */
final class Url
{
    /** Query parameters stripped during normalization (tracking only). */
    private const TRACKING_PARAMS = ['gclid', 'fbclid', 'msclkid', 'dclid', 'yclid', 'mc_cid', 'mc_eid', '_ga', 'ref_src', 'igshid'];

    /** Common multi-label public suffixes (subset; used for registrable-domain grouping). */
    private const MULTI_SUFFIXES = [
        'co.in', 'net.in', 'org.in', 'firm.in', 'gen.in', 'ind.in', 'ac.in', 'edu.in', 'res.in', 'gov.in', 'nic.in',
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'ac.uk', 'gov.uk', 'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
        'co.nz', 'org.nz', 'co.za', 'com.br', 'com.mx', 'com.sg', 'com.my', 'com.pk', 'com.bd', 'com.np', 'com.lk',
        'co.jp', 'ne.jp', 'or.jp', 'co.kr', 'com.cn', 'com.hk', 'com.tw', 'co.id', 'com.ph', 'com.vn', 'com.tr', 'com.sa', 'co.ae',
        'github.io', 'blogspot.com', 'wordpress.com', 'herokuapp.com', 'netlify.app', 'vercel.app', 'pages.dev',
    ];

    /**
     * Normalize a URL for storage and comparison. Returns null when the input is not an absolute
     * http(s) URL. Adds https:// when the scheme is missing.
     */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F\s]/', $url)) {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            if (str_starts_with($url, '//')) {
                $url = 'https:' . $url;
            } elseif (preg_match('#^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+([/:?\#].*)?$#i', $url)) {
                $url = 'https://' . $url;
            } else {
                return null;
            }
        }
        $p = parse_url($url);
        if ($p === false || empty($p['host']) || empty($p['scheme'])) {
            return null;
        }
        $scheme = strtolower($p['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = self::normalizeHost($p['host']);
        if ($host === null) {
            return null;
        }
        $port = isset($p['port']) ? (int)$p['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }
        $path = self::normalizePath($p['path'] ?? '/');
        $query = '';
        if (isset($p['query']) && $p['query'] !== '') {
            $pairs = [];
            foreach (explode('&', $p['query']) as $pair) {
                if ($pair === '') {
                    continue;
                }
                [$k] = explode('=', $pair, 2) + [1 => ''];
                $kl = strtolower(rawurldecode($k));
                if (str_starts_with($kl, 'utm_') || in_array($kl, self::TRACKING_PARAMS, true)) {
                    continue;
                }
                $pairs[] = $pair;
            }
            sort($pairs, SORT_STRING);
            $query = $pairs ? '?' . implode('&', $pairs) : '';
        }
        $userinfo = isset($p['user']) ? rawurlencode(rawurldecode($p['user'])) . (isset($p['pass']) ? ':' . rawurlencode(rawurldecode($p['pass'])) : '') . '@' : '';
        return $scheme . '://' . $userinfo . $host . ($port ? ':' . $port : '') . $path . $query;
    }

    public static function normalizeHost(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '') {
            return null;
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $host : null;
        }
        if (preg_match('/[^\x20-\x7e]/', $host)) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $host = strtolower($ascii);
        }
        if (strlen($host) > 253 || !preg_match('/^[a-z0-9_]([a-z0-9_\-]{0,62})(\.[a-z0-9_]([a-z0-9_\-]{0,62}))*$/', $host)) {
            return null;
        }
        return $host;
    }

    private static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        // Decode unreserved characters, upper-case remaining percent-encodings.
        $path = preg_replace_callback('/%([0-9a-fA-F]{2})/', static function (array $m): string {
            $c = chr(hexdec($m[1]));
            return preg_match('/[A-Za-z0-9\-._~]/', $c) ? $c : '%' . strtoupper($m[1]);
        }, $path) ?? $path;
        $path = self::removeDotSegments($path);
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        return $path === '' ? '/' : $path;
    }

    public static function removeDotSegments(string $path): string
    {
        $out = [];
        $segments = explode('/', $path);
        $last = end($segments);
        foreach ($segments as $seg) {
            if ($seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
                continue;
            }
            $out[] = $seg;
        }
        $result = implode('/', $out);
        if (($last === '.' || $last === '..') && !str_ends_with($result, '/')) {
            $result .= '/';
        }
        if (!str_starts_with($result, '/')) {
            $result = '/' . $result;
        }
        return $result;
    }

    /** Resolve a (possibly relative) reference against a base URL (RFC 3986 §5.2). */
    public static function resolve(string $base, string $ref): ?string
    {
        $ref = trim(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($ref === '' || str_starts_with($ref, '#')) {
            return null;
        }
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $ref, $m)) {
            $scheme = strtolower($m[1]);
            return in_array($scheme, ['http', 'https'], true) ? $ref : null;
        }
        $b = parse_url($base);
        if ($b === false || empty($b['host'])) {
            return null;
        }
        $scheme = $b['scheme'] ?? 'https';
        $authority = $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($ref, '//')) {
            return $scheme . ':' . $ref;
        }
        $refNoFrag = explode('#', $ref, 2)[0];
        if (str_starts_with($refNoFrag, '?')) {
            return $scheme . '://' . $authority . ($b['path'] ?? '/') . $refNoFrag;
        }
        if (str_starts_with($refNoFrag, '/')) {
            [$p, $q] = explode('?', $refNoFrag, 2) + [1 => null];
            return $scheme . '://' . $authority . self::removeDotSegments($p) . ($q !== null ? '?' . $q : '');
        }
        $basePath = $b['path'] ?? '/';
        $dir = substr($basePath, 0, (int)strrpos($basePath, '/') + 1);
        if ($dir === '') {
            $dir = '/';
        }
        [$p, $q] = explode('?', $refNoFrag, 2) + [1 => null];
        return $scheme . '://' . $authority . self::removeDotSegments($dir . $p) . ($q !== null ? '?' . $q : '');
    }

    public static function host(string $url): ?string
    {
        $h = parse_url($url, PHP_URL_HOST);
        return is_string($h) ? self::normalizeHost($h) : null;
    }

    /** Host without a leading "www." */
    public static function domain(string $url): ?string
    {
        $h = self::host($url);
        return $h === null ? null : (str_starts_with($h, 'www.') ? substr($h, 4) : $h);
    }

    /** Approximate registrable domain (eTLD+1) for grouping and same-site checks. */
    public static function registrableDomain(string $hostOrUrl): string
    {
        $host = str_contains($hostOrUrl, '/') ? (self::host($hostOrUrl) ?? $hostOrUrl) : strtolower($hostOrUrl);
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return $host;
        }
        $labels = explode('.', $host);
        $n = count($labels);
        if ($n <= 2) {
            return $host;
        }
        $lastTwo = $labels[$n - 2] . '.' . $labels[$n - 1];
        if (in_array($lastTwo, self::MULTI_SUFFIXES, true)) {
            return $labels[$n - 3] . '.' . $lastTwo;
        }
        return $lastTwo;
    }

    /** Duplicate-detection key: scheme- and www-insensitive, trailing-slash-insensitive. */
    public static function dedupeKey(string $normalizedUrl): string
    {
        $u = preg_replace('#^https?://(www\.)?#i', '', $normalizedUrl) ?? $normalizedUrl;
        $u = preg_replace('#/(\?|$)#', '$1', $u) ?? $u;
        return hash('sha256', strtolower($u));
    }

    public static function hash(string $url): string
    {
        return hash('sha256', self::normalize($url) ?? $url);
    }

    /** True when $url points at $domain or one of its subdomains (www-insensitive). */
    public static function belongsToDomain(string $url, string $domain): bool
    {
        $host = self::domain($url);
        $domain = strtolower(preg_replace('/^www\./', '', $domain) ?? $domain);
        return $host !== null && ($host === $domain || str_ends_with($host, '.' . $domain));
    }

    /** Compare two URLs ignoring scheme, www, trailing slash and tracking params. */
    public static function sameResource(string $a, string $b): bool
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);
        return $na !== null && $nb !== null && self::dedupeKey($na) === self::dedupeKey($nb);
    }
}
