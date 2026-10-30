<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;

/**
 * Android app distribution info. Everything comes from admin settings that are filled in from a
 * real, published release — nothing is assumed. When no download URL is set, the site says so.
 */
final class AppRelease
{
    public function __construct(private App $app)
    {
    }

    private function s(string $k): string
    {
        return trim((string)$this->app->settings()->get($k, ''));
    }

    public function available(): bool
    {
        return $this->downloadUrl() !== '';
    }

    public function downloadUrl(): string
    {
        return $this->s('android.download_url');
    }

    public function releasesUrl(): string
    {
        return $this->s('android.releases_url');
    }

    public function version(): string
    {
        return $this->s('android.version');
    }

    public function releaseDate(): string
    {
        return $this->s('android.release_date');
    }

    public function sha256(): string
    {
        return $this->s('android.sha256');
    }

    /** The URL a QR code encodes: the configured APK download, else the official releases page. Never a placeholder. */
    public function qrTarget(): ?string
    {
        $u = $this->downloadUrl() ?: $this->releasesUrl();
        return $u !== '' ? $u : null;
    }

    /** Digital Asset Links statement for Android App Links, or null when not configured. */
    public function assetLinks(): ?array
    {
        $pkg = $this->s('android.package');
        $fp = $this->s('android.cert_sha256');
        if ($pkg === '' || $fp === '') {
            return null;
        }
        return [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => $pkg, 'sha256_cert_fingerprints' => [$fp]],
        ]];
    }

    /** True when the request comes from our Android WebView shell (it appends this UA token). */
    public static function isInApp(?string $userAgent): bool
    {
        return $userAgent !== null && str_contains($userAgent, 'FlatmatesAndroid/');
    }
}
