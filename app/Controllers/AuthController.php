<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Http\Response;
use BharatSeo\Http\View;
use BharatSeo\Security\Auth;
use BharatSeo\Security\Session;
use BharatSeo\Services\MailerService;
use BharatSeo\Services\UserService;

final class AuthController extends Controller
{
    private function guestView(string $template, array $data = []): string
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        return View::render($template, $data + ['flash' => Session::pullFlash(), 'errors' => $errors], 'layouts/auth');
    }

    public function showLogin(): string|Response
    {
        if ($this->user) {
            return $this->redirect('/');
        }
        return $this->guestView('auth/login', ['title' => 'Sign in']);
    }

    public function login(): Response
    {
        $email = mb_substr(trim((string)$this->req->input('email', '')), 0, 190);
        $password = (string)$this->req->input('password', '');
        if ($email === '' || $password === '') {
            $this->flash('danger', 'Enter your email and password.');
            return $this->redirect('/login');
        }
        $ip = $this->req->ip((array)$this->app->config->get('security.trusted_proxies', []));
        $result = $this->app->make(Auth::class)->attempt($email, $password, $ip);
        if ($result === 'throttled') {
            $this->flash('danger', 'Too many failed sign-in attempts. Please wait ' . Auth::THROTTLE_WINDOW_MINUTES . ' minutes and try again.');
            return $this->redirect('/login');
        }
        if ($result !== 'ok') {
            $this->flash('danger', 'Invalid email or password.');
            $_SESSION['_old'] = ['email' => $email];
            return $this->redirect('/login');
        }
        $intended = $_SESSION['_intended'] ?? '/';
        unset($_SESSION['_intended']);
        if (!is_string($intended) || !preg_match('#^/[A-Za-z0-9/_\-]*$#', $intended)) {
            $intended = '/';
        }
        return $this->redirect($intended);
    }

    public function logout(): Response
    {
        $this->app->make(Auth::class)->logout($this->req->ip());
        Session::start($this->app, $this->req->isHttps());
        $this->flash('success', 'You have been signed out.');
        return $this->redirect('/login');
    }

    public function showForgot(): string
    {
        return $this->guestView('auth/forgot', ['title' => 'Forgot password', 'smtp' => $this->app->make(MailerService::class)->isConfigured()]);
    }

    public function forgot(): Response
    {
        $email = mb_substr(trim((string)$this->req->input('email', '')), 0, 190);
        $ip = $this->req->ip();
        $limiter = $this->app->make(\BharatSeo\Security\RateLimiter::class);
        if (!$limiter->hit('reset:' . $ip, 5, 3600)) {
            $this->flash('danger', 'Too many reset requests. Please try again later.');
            return $this->redirect('/forgot-password');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->app->make(Auth::class)->requestPasswordReset($email, $ip);
        }
        // Identical response whether or not the account exists.
        $this->flash('success', 'If an account exists for that email, a reset link has been sent. The link expires in ' . Auth::RESET_TTL_MINUTES . ' minutes.');
        return $this->redirect('/login');
    }

    public function showReset(): string|Response
    {
        $token = (string)$this->req->query('token', '');
        if ($this->app->make(Auth::class)->findValidReset($token) === null) {
            $this->flash('danger', 'This reset link is invalid or has expired.');
            return $this->redirect('/forgot-password');
        }
        return $this->guestView('auth/reset', ['title' => 'Choose a new password', 'token' => $token]);
    }

    public function reset(): Response
    {
        $token = (string)$this->req->input('token', '');
        $password = (string)$this->req->input('password', '');
        if ($password !== (string)$this->req->input('password_confirmation', '')) {
            $this->flash('danger', 'The passwords do not match.');
            return Response::redirect(url('/reset-password', ['token' => $token]));
        }
        $problems = $this->app->make(Auth::class)->completePasswordReset($token, $password, $this->req->ip());
        if ($problems) {
            $this->flash('danger', implode(' ', $problems));
            return Response::redirect(url('/reset-password', ['token' => $token]));
        }
        $this->flash('success', 'Your password was changed. Please sign in.');
        return $this->redirect('/login');
    }

    public function profile(): string
    {
        return $this->view('auth/profile', ['title' => 'My profile']);
    }

    public function changePassword(): Response
    {
        $new = (string)$this->req->input('password', '');
        if ($new !== (string)$this->req->input('password_confirmation', '')) {
            $this->flash('danger', 'The new passwords do not match.');
            return $this->redirect('/profile');
        }
        $problems = $this->app->make(UserService::class)->changeOwnPassword($this->user(), (string)$this->req->input('current_password', ''), $new);
        if ($problems) {
            $this->flash('danger', implode(' ', $problems));
        } else {
            Session::regenerate();
            $this->flash('success', 'Password updated.');
        }
        return $this->redirect('/profile');
    }
}
