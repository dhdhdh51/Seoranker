<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Http\Response;
use BharatSeo\Http\View;
use BharatSeo\Security\RateLimiter;
use BharatSeo\Services\SuppressionService;

/** Unauthenticated endpoints (unsubscribe). */
final class PublicController extends Controller
{
    public function unsubscribeForm(string $token): string
    {
        $exists = (bool)$this->app->db()->fetchColumn('SELECT 1 FROM outreach_messages WHERE unsubscribe_token = ?', [$token]);
        return View::render('public/unsubscribe', ['title' => 'Unsubscribe', 'token' => $token, 'valid' => $exists, 'done' => false], 'layouts/auth');
    }

    public function unsubscribe(string $token): string|Response
    {
        if (!$this->app->make(RateLimiter::class)->hit('unsub:' . $this->req->ip(), 30, 3600)) {
            return Response::html('Too many requests', 429);
        }
        $email = $this->app->make(SuppressionService::class)->unsubscribeByToken($token);
        // One-click (RFC 8058) clients only need a 2xx.
        if ($this->req->input('List-Unsubscribe') === 'One-Click') {
            return Response::html('Unsubscribed', $email ? 200 : 404);
        }
        return View::render('public/unsubscribe', ['title' => 'Unsubscribe', 'token' => $token, 'valid' => $email !== null, 'done' => $email !== null], 'layouts/auth');
    }
}
