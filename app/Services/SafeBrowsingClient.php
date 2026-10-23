<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;

/**
 * Optional Google Safe Browsing v4 Lookup API integration (free API key, usage limits apply).
 * Returns null ("Unknown") whenever the integration is not configured or the call fails.
 */
class SafeBrowsingClient
{
    public function __construct(private App $app)
    {
    }

    public function isConfigured(): bool
    {
        return $this->app->settings()->has('safebrowsing.api_key');
    }

    /** @return 'safe'|'unsafe'|null */
    public function check(string $url): ?string
    {
        if (!$this->isConfigured() || !function_exists('curl_init')) {
            return null;
        }
        $key = (string)$this->app->settings()->get('safebrowsing.api_key');
        $payload = json_encode([
            'client' => ['clientId' => 'bharatseo', 'clientVersion' => APP_VERSION],
            'threatInfo' => [
                'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                'platformTypes' => ['ANY_PLATFORM'],
                'threatEntryTypes' => ['URL'],
                'threatEntries' => [['url' => $url]],
            ],
        ]);
        $ch = curl_init('https://safebrowsing.googleapis.com/v4/threatMatches:find?key=' . rawurlencode($key));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $status !== 200) {
            $this->app->logger()->warning('Safe Browsing lookup failed', ['status' => $status]);
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }
        return empty($json['matches']) ? 'safe' : 'unsafe';
    }
}
