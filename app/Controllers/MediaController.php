<?php
declare(strict_types=1);

namespace Flatmates\Controllers;

use Flatmates\Core\HttpException;
use Flatmates\Http\Response;

/**
 * Serves uploaded images from storage (outside the web root). Listing photos are public when the
 * listing is publicly visible; profile photos are only for signed-in members.
 */
final class MediaController extends Controller
{
    public function show(string $dir, string $file): Response
    {
        $rel = $dir . '/' . $file;
        $db = $this->app->db();
        $u = $this->currentUser;
        if ($dir === 'photos') {
            if (!$u) {
                throw new HttpException(404);
            }
            $owner = $db->fetchColumn('SELECT user_id FROM profiles WHERE photo_path = ?', [$rel]);
            if (!$owner) {
                throw new HttpException(404);
            }
            if ((int)$owner !== $u->id && !$u->isStaff() && $this->app->make(\Flatmates\Services\SafetyService::class)->blockedEitherWay($u->id, (int)$owner)) {
                throw new HttpException(404);
            }
        } else {
            $l = $db->fetch('SELECT l.user_id, l.status, l.visibility FROM listing_photos p JOIN listings l ON l.id = p.listing_id WHERE p.path = ?', [$rel]);
            if (!$l) {
                throw new HttpException(404);
            }
            $privileged = $u && ((int)$l['user_id'] === $u->id || $u->can('listings'));
            if (!$privileged && ($l['status'] !== 'published' || ($l['visibility'] === 'members' && !$u))) {
                throw new HttpException(404);
            }
        }
        $path = $this->app->config->storagePath('uploads/' . $rel);
        if (!is_file($path)) {
            throw new HttpException(404);
        }
        $public = $dir === 'listings';
        $body = (string)file_get_contents($path);
        return new Response($body, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string)strlen($body),
            'Cache-Control' => $public ? 'public, max-age=604800' : 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
