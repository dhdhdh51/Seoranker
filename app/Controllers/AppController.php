<?php
declare(strict_types=1);

namespace Flatmates\Controllers;

use Flatmates\Core\HttpException;
use Flatmates\Http\Response;
use Flatmates\Services\AppRelease;
use Flatmates\Support\QrCode;

/** Android app download page, QR code and Digital Asset Links (App Links verification). */
final class AppController extends Controller
{
    public function show(): Response
    {
        $rel = $this->app->make(AppRelease::class);
        return $this->view('pages/app', [
            'title' => 'Download the Android app',
            'description' => 'Get the official ' . brand() . ' Android app: swipe, match and chat with student flatmates on the go.',
            'canonical' => absolute_url('/app'), 'rel' => $rel,
        ]);
    }

    public function qr(): Response
    {
        $target = $this->app->make(AppRelease::class)->qrTarget();
        if ($target === null) {
            throw new HttpException(404);
        }
        return new Response(QrCode::encode($target)->svg(4, '#142b4a', 'QR code: ' . $target), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=600', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function assetLinks(): Response
    {
        $links = $this->app->make(AppRelease::class)->assetLinks();
        if ($links === null) {
            throw new HttpException(404);
        }
        return new Response((string)json_encode($links, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=3600']);
    }
}
