<?php
declare(strict_types=1);

namespace BharatSeo\Crawler;

/**
 * robots.txt parser (RFC 9309): user-agent groups, Allow/Disallow with longest
 * match precedence, "*" and "$" wildcards, and Crawl-delay (non-standard, honoured).
 */
final class RobotsTxt
{
    /** @var array<int, array{agents:string[], rules:array<int,array{0:bool,1:string}>, delay:?float}> */
    private array $groups = [];

    public function __construct(private string $body, private bool $allowAll = false, private bool $disallowAll = false)
    {
        if (!$allowAll && !$disallowAll) {
            $this->parse($body);
        }
    }

    public static function allowAll(): self
    {
        return new self('', true);
    }

    public static function disallowAll(): self
    {
        return new self('', false, true);
    }

    private function parse(string $body): void
    {
        $body = substr($body, 0, 500000);
        $current = null;
        $lastWasAgent = false;
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if (!$lastWasAgent || $current === null) {
                    $this->groups[] = ['agents' => [], 'rules' => [], 'delay' => null];
                    $current = array_key_last($this->groups);
                }
                $this->groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;
                continue;
            }
            $lastWasAgent = false;
            if ($current === null) {
                continue;
            }
            if ($field === 'allow' || $field === 'disallow') {
                if ($value === '' && $field === 'disallow') {
                    continue; // empty disallow = allow everything
                }
                $this->groups[$current]['rules'][] = [$field === 'allow', $value];
            } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                $this->groups[$current]['delay'] = min(60.0, max(0.0, (float)$value));
            }
        }
    }

    private function groupFor(string $userAgent): ?array
    {
        $token = strtolower(preg_replace('#/.*$#', '', $userAgent) ?? $userAgent);
        $best = null;
        $star = null;
        foreach ($this->groups as $g) {
            foreach ($g['agents'] as $a) {
                if ($a === '*') {
                    $star ??= $g;
                } elseif ($a !== '' && str_contains($token, $a)) {
                    if ($best === null) {
                        $best = $g;
                    } else {
                        $best['rules'] = array_merge($best['rules'], $g['rules']);
                    }
                }
            }
        }
        return $best ?? $star;
    }

    public function isAllowed(string $url, string $userAgent): bool
    {
        if ($this->allowAll) {
            return true;
        }
        if ($this->disallowAll) {
            return false;
        }
        $path = (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }
        if ($path === '/robots.txt') {
            return true;
        }
        $group = $this->groupFor($userAgent);
        if ($group === null) {
            return true;
        }
        $bestLen = -1;
        $allowed = true;
        foreach ($group['rules'] as [$isAllow, $pattern]) {
            if (self::matches($pattern, $path)) {
                $len = strlen($pattern);
                if ($len > $bestLen || ($len === $bestLen && $isAllow)) {
                    $bestLen = $len;
                    $allowed = $isAllow;
                }
            }
        }
        return $allowed;
    }

    public function crawlDelay(string $userAgent): ?float
    {
        return $this->groupFor($userAgent)['delay'] ?? null;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($anchored ? '$' : '') . '#';
        return (bool)preg_match($regex, rawurldecode($path)) || (bool)preg_match($regex, $path);
    }
}
