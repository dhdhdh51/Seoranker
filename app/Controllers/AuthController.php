<?php
declare(strict_types=1);

namespace Flatmates\Controllers;

use Flatmates\Core\HttpException;
use Flatmates\Core\ValidationException;
use Flatmates\Http\Response;
use Flatmates\Security\Auth;
use Flatmates\Security\RateLimiter;
use Flatmates\Services\SsoReceiver;

final class AuthController extends Controller
{
    private function auth(): Auth
    {
        return $this->app->make(Auth::class);
    }

    private function ip(): string
    {
        return $this->req->ip((array)$this->app->config->get('security.trusted_proxies', []));
    }

    private function home(): Response
    {
        $u = $this->auth()->user();
        if ($u && $u->isStaff()) {
            return $this->redirect('/admin');
        }
        $intended = $_SESSION['_intended'] ?? null;
        unset($_SESSION['_intended']);
        if (is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//') && !str_starts_with($intended, '/api/')) {
            return $this->redirect($intended);
        }
        return $this->redirect('/dashboard');
    }

    public function registerForm(): Response
    {
        if ($this->currentUser) {
            return $this->home();
        }
        return $this->view('auth/register', ['title' => 'Create your account', 'minAge' => (int)$this->app->settings()->get('rules.min_age', 18)], 'layouts/auth');
    }

    public function register(): Response
    {
        if (!$this->app->make(RateLimiter::class)->hit('register:' . $this->ip(), 10, 3600)) {
            throw new HttpException(429, 'Too many sign-ups from your network. Please try again later.');
        }
        if ((string)$this->req->input('website', '') !== '') {
            throw new HttpException(400); // honeypot
        }
        $id = $this->auth()->register($this->req->all());
        $_SESSION['pending_uid'] = $id;
        $this->flash('success', 'Account created! We sent a 6-digit code to your email.');
        return $this->redirect('/verify-email');
    }

    private function pendingUser(): ?array
    {
        $id = $_SESSION['pending_uid'] ?? null;
        return is_int($id) ? $this->app->db()->fetch('SELECT id, email, email_verified_at FROM users WHERE id = ?', [$id]) : null;
    }

    public function verifyForm(): Response
    {
        $u = $this->pendingUser();
        if (!$u) {
            return $this->redirect('/login');
        }
        if ($u['email_verified_at']) {
            return $this->redirect('/onboarding/1');
        }
        $email = (string)$u['email'];
        $masked = preg_replace('/(?<=^.).*(?=.@)/u', '•••', $email) ?? $email;
        return $this->view('auth/verify', ['title' => 'Verify your email', 'masked' => $masked], 'layouts/auth');
    }

    public function verify(): Response
    {
        $u = $this->pendingUser();
        if (!$u) {
            return $this->redirect('/login');
        }
        if (!$this->app->make(RateLimiter::class)->hit('verify:' . $this->ip(), 30, 3600)) {
            throw new HttpException(429);
        }
        $this->auth()->verifyEmail((int)$u['id'], (string)$this->req->input('code', ''));
        unset($_SESSION['pending_uid']);
        $this->auth()->startSession((int)$u['id']);
        $this->flash('success', 'Email verified. Let\'s set up your profile.');
        return $this->redirect('/onboarding/1');
    }

    public function resend(): Response
    {
        $u = $this->pendingUser();
        if (!$u) {
            return $this->redirect('/login');
        }
        $this->auth()->sendVerification((int)$u['id']);
        $this->flash('success', 'A new code is on its way.');
        return $this->redirect('/verify-email');
    }

    public function loginForm(): Response
    {
        if ($this->currentUser) {
            return $this->home();
        }
        return $this->view('auth/login', ['title' => 'Sign in'], 'layouts/auth');
    }

    public function login(): Response
    {
        $email = (string)$this->req->input('email', '');
        $result = $this->auth()->attempt($email, (string)$this->req->input('password', ''), $this->ip());
        $msg = match ($result) {
            'ok' => null,
            'throttled' => 'Too many failed attempts. Wait 15 minutes or reset your password.',
            'suspended' => 'This account is not available. Contact support if you think this is a mistake.',
            'maintenance' => 'We are doing some maintenance. Please try again shortly.',
            default => 'Email or password is incorrect.',
        };
        if ($msg !== null) {
            $_SESSION['_old'] = ['email' => $email];
            $this->flash('danger', $msg);
            return $this->redirect('/login');
        }
        $u = $this->auth()->user();
        if ($u && $u->emailVerifiedAt === null) {
            $_SESSION['pending_uid'] = $u->id;
            try {
                $this->auth()->sendVerification($u->id);
            } catch (ValidationException) {
            }
            return $this->redirect('/verify-email');
        }
        return $this->home();
    }

    public function logout(): Response
    {
        $this->auth()->logout();
        return $this->redirect('/');
    }

    public function forgotForm(): Response
    {
        return $this->view('auth/forgot', ['title' => 'Forgot password'], 'layouts/auth');
    }

    public function forgot(): Response
    {
        if (!$this->app->make(RateLimiter::class)->hit('forgot:' . $this->ip(), 10, 3600)) {
            throw new HttpException(429);
        }
        $email = strtolower(trim((string)$this->req->input('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::single('email', 'Enter your email address.');
        }
        $this->auth()->requestReset($email);
        $_SESSION['reset_email'] = $email;
        $this->flash('success', 'If an account exists for that email, we have sent a 6-digit reset code.');
        return $this->redirect('/reset-password');
    }

    public function resetForm(): Response
    {
        return $this->view('auth/reset', ['title' => 'Choose a new password', 'email' => $_SESSION['reset_email'] ?? ''], 'layouts/auth');
    }

    public function reset(): Response
    {
        if (!$this->app->make(RateLimiter::class)->hit('reset:' . $this->ip(), 20, 3600)) {
            throw new HttpException(429);
        }
        if ((string)$this->req->input('password') !== (string)$this->req->input('password_confirmation')) {
            throw ValidationException::single('password_confirmation', 'Passwords do not match.');
        }
        $this->auth()->resetPassword((string)$this->req->input('email', ''), (string)$this->req->input('code', ''), (string)$this->req->input('password', ''));
        unset($_SESSION['reset_email']);
        $this->flash('success', 'Password changed. Sign in with your new password.');
        return $this->redirect('/login');
    }

    /** Staff sign-in from the NoidaRankers platform. */
    public function sso(): Response
    {
        if (!$this->app->make(RateLimiter::class)->hit('sso:' . $this->ip(), 20, 600)) {
            throw new HttpException(429);
        }
        $id = $this->app->make(SsoReceiver::class)->consume((string)$this->req->query('token', ''));
        if ($id === null) {
            $this->flash('danger', 'NoidaRankers sign-in link is invalid or expired, or your email has no staff account here.');
            return $this->redirect('/login');
        }
        $this->auth()->startSession($id);
        $this->app->db()->run('UPDATE users SET last_login_at = UTC_TIMESTAMP(), last_login_ip = ? WHERE id = ?', [$this->ip(), $id]);
        $this->flash('success', 'Signed in via NoidaRankers.');
        return $this->redirect('/admin');
    }
}
