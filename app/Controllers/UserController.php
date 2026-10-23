<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Http\Response;
use BharatSeo\Security\Auth;
use BharatSeo\Services\AuditLogger;
use BharatSeo\Services\UserService;

final class UserController extends Controller
{
    private function svc(): UserService
    {
        return $this->app->make(UserService::class);
    }

    public function index(): string
    {
        return $this->view('users/index', ['title' => 'Users & roles', 'users' => $this->svc()->list()]);
    }

    public function create(): string
    {
        return $this->view('users/form', ['title' => 'New user', 'u' => null]);
    }

    public function store(): Response
    {
        $id = $this->svc()->save($this->user(), null, $this->req->all());
        $this->flash('success', 'User created. Assign client access or project membership next.');
        return $this->redirect('/users/' . $id . '/edit');
    }

    public function edit(int $id): string
    {
        $u = $this->svc()->find($id);
        $db = $this->app->db();
        $resetLink = $_SESSION['_reset_link'][$id] ?? null;
        unset($_SESSION['_reset_link']); // shown once
        return $this->view('users/form', [
            'title' => 'Edit user',
            'u' => $u,
            'clientLinks' => $db->fetchAll('SELECT cu.*, c.name FROM client_users cu JOIN clients c ON c.id = cu.client_id WHERE cu.user_id = ?', [$id]),
            'projectLinks' => $db->fetchAll('SELECT pm.*, p.name FROM project_members pm JOIN projects p ON p.id = pm.project_id WHERE pm.user_id = ?', [$id]),
            'resetLink' => $resetLink,
        ]);
    }

    public function update(int $id): Response
    {
        $this->svc()->save($this->user(), $id, $this->req->all());
        $this->flash('success', 'User saved.');
        return $this->redirect('/users/' . $id . '/edit');
    }

    /** Manual fallback when SMTP is unavailable: generate a one-time reset link to hand over securely. */
    public function resetLink(int $id): Response
    {
        $u = $this->svc()->find($id);
        $token = $this->app->make(Auth::class)->requestPasswordReset((string)$u['email'], $this->req->ip(), false);
        if ($token === null) {
            $this->flash('warning', 'Could not create a reset link (user disabled or too many recent requests).');
        } else {
            $_SESSION['_reset_link'] = [$id => absolute_url('/reset-password', ['token' => $token])];
            $this->app->make(AuditLogger::class)->log('user.reset_link_generated', $this->user()->id, null, ['user_id' => $id], 'user', null, $id);
            $this->flash('success', 'One-time reset link generated (valid ' . Auth::RESET_TTL_MINUTES . ' minutes). Share it with the user over a secure channel.');
        }
        return $this->redirect('/users/' . $id . '/edit');
    }
}
