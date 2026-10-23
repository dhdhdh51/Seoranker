<?php
declare(strict_types=1);

namespace BharatSeo\Http;

use BharatSeo\Core\App;
use BharatSeo\Core\ErrorHandler;
use BharatSeo\Core\HttpException;
use BharatSeo\Core\NotInstalledException;
use BharatSeo\Core\ValidationException;
use BharatSeo\Security\Auth;
use BharatSeo\Security\Csrf;
use BharatSeo\Security\Session;
use BharatSeo\Services\NotificationService;

/** Front-controller pipeline: headers → session → route → CSRF → auth → roles → module → controller. */
final class Kernel
{
    public static function handle(): void
    {
        self::securityHeaders(false);
        try {
            $app = App::boot();
        } catch (NotInstalledException) {
            header('Location: ' . base_path() . '/install.php', true, 302);
            return;
        }
        $request = Request::fromGlobals();
        self::securityHeaders($request->isHttps());
        try {
            $response = self::dispatch($app, $request);
        } catch (\Throwable $e) {
            $response = self::renderException($app, $request, $e);
        }
        $response->send();
    }

    public static function securityHeaders(bool $https): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        if ($https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function router(): Router
    {
        $router = new Router();
        (require APP_ROOT . '/app/routes.php')($router);
        return $router;
    }

    public static function dispatch(App $app, Request $request): Response
    {
        $path = $request->path(base_path());
        $match = self::router()->match($request->method(), $path);
        if ($match['route'] === null) {
            throw new HttpException($match['allowed'] ? 405 : 404);
        }
        $route = $match['route'];
        $opts = $route['options'];

        Session::start($app, $request->isHttps());

        if ($request->isPost() && $opts['csrf'] && !Csrf::validate((string)($request->input('_csrf') ?? $request->header('X-CSRF-Token') ?? ''))) {
            throw new HttpException(419);
        }
        $auth = $app->make(Auth::class);
        $user = $auth->user();
        if ($opts['auth'] && $user === null) {
            if ($request->method() === 'GET') {
                $_SESSION['_intended'] = $path;
            }
            return Response::redirect(url('/login'));
        }
        if ($opts['roles'] && $user && !in_array($user->role, $opts['roles'], true)) {
            throw new HttpException(403);
        }
        if ($opts['module'] && !$app->settings()->moduleEnabled($opts['module'])) {
            throw new HttpException(403, 'This module is disabled by the administrator.');
        }
        if ($user) {
            View::share('currentUser', $user);
            View::share('unreadNotifications', $app->make(NotificationService::class)->unreadCount($user->id));
            if (!headers_sent()) {
                header('Cache-Control: no-store, private');
            }
        }
        View::share('currentPath', $path);

        [$class, $method] = $route['handler'];
        $controller = new $class($app, $request, $user);
        $result = $controller->$method(...array_values($match['args']));
        if ($request->method() === 'GET') {
            unset($_SESSION['_old']); // old input is consumed by the form re-render
        }
        return $result instanceof Response ? $result : Response::html((string)$result);
    }

    public static function renderException(App $app, Request $request, \Throwable $e): Response
    {
        if ($e instanceof ValidationException) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['_errors'] = $e->errors;
                $_SESSION['_old'] = array_map(static fn($v) => is_scalar($v) ? (string)$v : '', array_diff_key($request->all(), ['_csrf' => 1, 'password' => 1, 'password_confirmation' => 1, 'smtp_password' => 1]));
                Session::flash('danger', $e->getMessage() === 'Please correct the highlighted fields.' ? implode(' ', $e->errors) : $e->getMessage());
            }
            return Response::redirect(self::backUrl($request));
        }
        $status = $e instanceof HttpException ? $e->status : 500;
        if ($status === 500) {
            ErrorHandler::report($e);
        }
        $message = $e instanceof HttpException ? $e->getMessage() : 'An unexpected error occurred. The incident has been logged.';
        if ($status === 500 && $app->isDebug()) {
            $message .= ' [' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        }
        if ($status === 419 && session_status() === PHP_SESSION_ACTIVE) {
            Session::flash('warning', $message);
            return Response::redirect(self::backUrl($request));
        }
        $accept = (string)$request->header('Accept');
        if (str_contains($accept, 'application/json')) {
            return Response::json(['error' => $message], $status);
        }
        try {
            $html = View::render('errors/error', ['status_code' => $status, 'message' => $message, 'title' => HttpException::defaultMessage($status)], 'layouts/minimal');
        } catch (\Throwable) {
            $html = '<h1>Error ' . $status . '</h1><p>' . e($message) . '</p>';
        }
        return Response::html($html, $status);
    }

    /** Same-origin referer or dashboard. */
    public static function backUrl(Request $request): string
    {
        $ref = $request->referer();
        if ($ref) {
            $host = parse_url($ref, PHP_URL_HOST);
            $reqHost = explode(':', (string)$request->header('Host'))[0];
            if ($host !== null && $host === $reqHost) {
                $path = (string)(parse_url($ref, PHP_URL_PATH) ?? '/');
                $q = parse_url($ref, PHP_URL_QUERY);
                return $path . ($q ? '?' . $q : '');
            }
        }
        return url('/');
    }
}
