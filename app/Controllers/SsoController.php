<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Core\HttpException;
use BharatSeo\Http\Response;
use BharatSeo\Services\SsoService;

final class SsoController extends Controller
{
    /** Redirect the signed-in user to the website admin with a one-time signed token. */
    public function website(): Response
    {
        $sso = $this->app->make(SsoService::class);
        if (!$sso->isEnabled()) {
            throw new HttpException(404);
        }
        if (!$sso->allowed($this->user())) {
            throw new HttpException(403, 'Your role is not allowed to sign in to the website admin.');
        }
        $target = $sso->websiteUrl() . '/admin/sso?token=' . rawurlencode($sso->tokenFor($this->user()));
        return new Response('', 303, ['Location' => $target, 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
