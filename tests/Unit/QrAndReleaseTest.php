<?php
declare(strict_types=1);

namespace Flatmates\Tests\Unit;

use Flatmates\Services\AppRelease;
use Flatmates\Services\SettingsService;
use Flatmates\Services\TrustService;
use Flatmates\Support\QrCode;
use Flatmates\Tests\TestCase;

final class QrAndReleaseTest extends TestCase
{
    /**
     * Reference fingerprints were produced with the independent python-qrcode library
     * (same text, version, level M and mask). All 8 masks were also decoded with OpenCV.
     */
    public function testMatrixMatchesIndependentReference(): void
    {
        $cases = [
            ['https://flatmates.noidarankers.in/app', 3, 2, '3433a2ca3d48afc7266aa91fc98745ad5ce06785c3e0712c9cde18345944eb89'],
            ['https://github.com/dhdhdh51/Seoranker/releases/download/flatmates-android-v1.0.0/student-flatmate-finder-1.0.0.apk', 7, 5, 'e2af059a33f96c3d7d33b224b5cf853583cae3220deb5402e821e246be828b0f'],
            ['A', 1, 0, '2bae48594b7325872d655a95d8814f09f3825eba52faa8c8b4e3c6dd453105e5'],
        ];
        foreach ($cases as [$text, $version, $mask, $sha]) {
            $q = QrCode::encode($text, $mask);
            $this->assertSame($version, $q->version, "version for $text");
            $this->assertSame($sha, hash('sha256', implode("\n", $q->rows())), "matrix for $text");
        }
    }

    public function testSvgAndLimits(): void
    {
        $svg = QrCode::encode('https://example.com/x')->svg(4, '#142b4a', 'QR <test>');
        $this->assertContains('<svg', $svg);
        $this->assertContains('aria-label="QR &lt;test&gt;"', $svg);
        $this->assertThrows(\InvalidArgumentException::class, fn() => QrCode::encode(str_repeat('a', 214)));
        $this->assertSame(10, QrCode::encode(str_repeat('a', 213))->version);
    }

    public function testReleaseSettingsValidation(): void
    {
        $this->assertFalse(SettingsService::validate('android.download_url', 'http://example.com/a.apk')[0], 'https only');
        $this->assertFalse(SettingsService::validate('android.download_url', 'javascript:alert(1)')[0]);
        $this->assertTrue(SettingsService::validate('android.download_url', 'https://github.com/o/r/releases/download/v1/app.apk')[0]);
        $this->assertTrue(SettingsService::validate('android.download_url', '')[0], 'empty = no release');
        $this->assertFalse(SettingsService::validate('android.sha256', 'abc')[0]);
        $this->assertSame([true, str_repeat('a', 64), null], SettingsService::validate('android.sha256', str_repeat('A', 64)));
        $this->assertFalse(SettingsService::validate('android.version', 'v1')[0]);
        $this->assertFalse(SettingsService::validate('android.package', 'Bad.Package')[0]);
        $this->assertFalse(SettingsService::validate('android.cert_sha256', 'AA:BB')[0]);
        $this->assertTrue(SettingsService::validate('android.cert_sha256', implode(':', array_fill(0, 32, 'ab')))[0]);
        $this->assertTrue(AppRelease::isInApp('Mozilla/5.0 (Linux; Android 14) FlatmatesAndroid/1.0.0'));
        $this->assertFalse(AppRelease::isInApp('Mozilla/5.0 Chrome'));
    }

    public function testListingBadgeNeedsPublishedAndReviewed(): void
    {
        $this->assertSame([], TrustService::forListing(['status' => 'published', 'reviewed_at' => null]));
        $this->assertSame([], TrustService::forListing(['status' => 'pending', 'reviewed_at' => '2026-01-01 00:00:00']));
        $this->assertSame(['listing_reviewed'], TrustService::forListing(['status' => 'published', 'reviewed_at' => '2026-01-01 00:00:00']));
        foreach (TrustService::BADGES as $b) {
            $this->assertTrue($b['means'] !== '' && $b['not'] !== '', 'every badge explains what it does not prove');
        }
    }
}
