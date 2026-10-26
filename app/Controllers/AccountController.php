<?php
declare(strict_types=1);

namespace Flatmates\Controllers;

use Flatmates\Http\Response;
use Flatmates\Services\AccountService;
use Flatmates\Services\SafetyService;

final class AccountController extends Controller
{
    public function index(): Response
    {
        $u = $this->user();
        $row = $this->app->db()->fetch('SELECT email, created_at, deletion_requested_at, status, status_reason FROM users WHERE id = ?', [$u->id]);
        return $this->view('account/index', ['title' => 'Account', 'account' => $row, 'blocked' => $this->app->make(SafetyService::class)->blockedList($u->id), 'graceDays' => AccountService::GRACE_DAYS, 'noindex' => true]);
    }

    public function password(): Response
    {
        $this->app->make(AccountService::class)->changePassword($this->user(), (string)$this->req->input('current_password', ''), (string)$this->req->input('password', ''), (string)$this->req->input('password_confirmation', ''));
        $this->flash('success', 'Password changed. Other devices have been signed out.');
        return $this->redirect('/account');
    }

    public function export(): Response
    {
        $data = $this->app->make(AccountService::class)->export($this->user());
        $r = Response::json($data);
        $r->body = (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r->headers['Content-Disposition'] = 'attachment; filename="my-flatmate-data-' . gmdate('Ymd') . '.json"';
        return $r;
    }

    public function delete(): Response
    {
        $this->app->make(AccountService::class)->requestDeletion($this->user(), (string)$this->req->input('password', ''));
        $this->flash('warning', 'Your account is scheduled for deletion in ' . AccountService::GRACE_DAYS . ' days. Your profile and listings are hidden now.');
        return $this->redirect('/account');
    }

    public function cancelDelete(): Response
    {
        $this->app->make(AccountService::class)->cancelDeletion($this->user());
        $this->flash('success', 'Deletion cancelled. Turn discovery back on in your profile privacy settings.');
        return $this->redirect('/account');
    }

    public function notifications(): Response
    {
        $rows = $this->app->db()->fetchAll('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$this->user()->id]);
        return $this->view('account/notifications', ['title' => 'Notifications', 'rows' => $rows, 'noindex' => true]);
    }

    public function readNotifications(): Response
    {
        $this->app->db()->run('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$this->user()->id]);
        return $this->redirect('/notifications');
    }
}
