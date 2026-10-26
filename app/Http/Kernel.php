<?php
declare(strict_types=1);

namespace Flatmates\Http;

use Flatmates\Core\App;
use Flatmates\Core\ErrorHandler;
use Flatmates\Core\HttpException;
use Flatmates\Core\NotInstalledException;
use Flatmates\Core\ValidationException;
use Flatmates\Security\Auth;
use Flatmates\Security\Csrf;
use Flatmates\Security\Session;
use Flatmates\Services\ChatService;
use Flatmates\Services\Notifier;
use Flatmates\Services\ProfileService;

/**
 * Front controller: security headers → session → route → CSRF → authentication → email
 * verification / onboarding gates → role & permission checks → controller.
 */
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
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'");
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
        if ($path !== '/' && str_ends_with($request->uri(), '/') && $request->method() === 'GET') {
            return Response::redirect(url($path), 301);
        }
        $match = self::router()->match($request->method(), $path);
        if ($match['route'] === null) {
            throw new HttpException($match['allowed'] ? 405 : 404);
        }
        $route = $match['route'];
        View::share('inApp', \Flatmates\Services\AppRelease::isInApp($request->userAgent()));
        $opts = $route['options'] + ['perm' => null, 'verified' => true, 'onboarded' => false, 'session' => true];

        if ($opts['session']) {
            Session::start($app, $request->isHttps());
        }
        if ($request->isPost() && $opts['csrf'] && !Csrf::validate((string)($request->input('_csrf') ?? $request->header('X-CSRF-Token') ?? ''))) {
            throw new HttpException(419);
        }
        $auth = $app->make(Auth::class);
        $user = $opts['session'] ? $auth->user() : null;
        $wantsJson = str_starts_with($path, '/api/');

        if ($app->settings()->bool('system.maintenance') && (!$user || !$user->isStaff()) && !in_array($path, ['/login', '/sso', '/robots.txt'], true)) {
            return Response::html(View::render('errors/maintenance', ['title' => 'Maintenance'], 'layouts/minimal'), 503);
        }
        if ($opts['auth'] && $user === null) {
            if ($wantsJson) {
                return Response::json(['error' => 'Please sign in again.'], 401);
            }
            if ($request->method() === 'GET') {
                $_SESSION['_intended'] = $path;
            }
            return Response::redirect(url('/login'));
        }
        if ($user && $opts['auth']) {
            if ($opts['verified'] && $user->emailVerifiedAt === null) {
                $_SESSION['pending_uid'] = $user->id;
                return $wantsJson ? Response::json(['error' => 'Verify your email first.'], 403) : Response::redirect(url('/verify-email'));
            }
            if ($opts['roles'] && !in_array($user->role, $opts['roles'], true)) {
                throw new HttpException(403);
            }
            if ($opts['perm'] && !$user->can($opts['perm'])) {
                throw new HttpException(403, 'You do not have the "' . $opts['perm'] . '" permission.');
            }
            if ($opts['onboarded'] && $user->isStudent()) {
                $p = $app->make(ProfileService::class)->ensure($user);
                if (!$p['onboarded_at']) {
                    return $wantsJson ? Response::json(['error' => 'Finish your profile first.'], 403) : Response::redirect(url('/onboarding/' . min(4, max(1, (int)$p['onboarding_step']))));
                }
            }
        }
        if ($user) {
            View::share('currentUser', $user);
            View::share('unreadNotifications', $app->make(Notifier::class)->unread($user->id));
            View::share('unreadMessages', $app->make(ChatService::class)->unreadTotal($user->id));
            if (!headers_sent()) {
                header('Cache-Control: no-store, private');
            }
        }
        View::share('currentPath', $path);

        [$class, $method] = $route['handler'];
        $controller = new $class($app, $request, $user);
        $result = $controller->$method(...array_values($match['args']));
        if ($request->method() === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['_old'], $_SESSION['_errors']);
        }
        return $result instanceof Response ? $result : Response::html((string)$result);
    }

    public static function renderException(App $app, Request $request, \Throwable $e): Response
    {
        $isApi = str_starts_with($request->path(base_path()), '/api/') || str_contains((string)$request->header('Accept'), 'application/json');
        if ($e instanceof ValidationException) {
            if ($isApi) {
                return Response::json(['error' => implode(' ', $e->errors), 'errors' => $e->errors], 422);
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['_errors'] = $e->errors;
                $_SESSION['_old'] = array_map(static fn($v) => is_scalar($v) ? (string)$v : $v, array_diff_key($request->all(), ['_csrf' => 1, 'password' => 1, 'password_confirmation' => 1, 'current_password' => 1, 'code' => 1]));
                Session::flash('danger', $e->getMessage() === 'Please correct the highlighted fields.' ? implode(' ', $e->errors) : $e->getMessage());
            }
            return Response::redirect(self::backUrl($request));
        }
        $status = $e instanceof HttpException ? $e->status : 500;
        if ($status === 500) {
            ErrorHandler::report($e);
        }
        $message = $e instanceof HttpException ? $e->getMessage() : 'Something went wrong on our side. The problem has been logged.';
        if ($status === 500 && $app->isDebug()) {
            $message .= ' [' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        }
        if ($isApi) {
            return Response::json(['error' => $message], $status);
        }
        if ($status === 419 && session_status() === PHP_SESSION_ACTIVE) {
            Session::flash('warning', $message);
            return Response::redirect(self::backUrl($request));
        }
        try {
            $html = View::render('errors/error', ['status_code' => $status, 'message' => $message, 'title' => HttpException::defaultMessage($status), 'noindex' => true], 'layouts/minimal');
        } catch (\Throwable) {
            $html = '<h1>Error ' . $status . '</h1><p>' . e($message) . '</p>';
        }
        return Response::html($html, $status);
    }

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
