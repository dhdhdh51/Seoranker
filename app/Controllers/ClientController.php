<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Http\Response;
use BharatSeo\Services\ClientService;
use BharatSeo\Services\UserService;

final class ClientController extends Controller
{
    private function svc(): ClientService
    {
        return $this->app->make(ClientService::class);
    }

    public function index(): string
    {
        return $this->view('clients/index', ['title' => 'Clients', 'clients' => $this->svc()->list($this->user())]);
    }

    public function create(): string
    {
        return $this->view('clients/form', ['title' => 'New client', 'client' => null]);
    }

    public function store(): Response
    {
        $id = $this->svc()->save($this->user(), null, $this->req->all());
        $this->flash('success', 'Client created.');
        return $this->redirect('/clients/' . $id);
    }

    public function show(int $id): string
    {
        $client = $this->svc()->find($this->user(), $id);
        $projects = $this->app->db()->fetchAll('SELECT * FROM projects WHERE client_id = ? ORDER BY name', [$id]);
        $ids = $this->gate->projectIds($this->user());
        if ($ids !== null) {
            $projects = array_values(array_filter($projects, static fn($p) => in_array((int)$p['id'], $ids, true)));
        }
        return $this->view('clients/show', [
            'title' => $client['name'],
            'client' => $client,
            'projects' => $projects,
            'users' => $this->user()->isSuperAdmin() ? $this->svc()->users($id) : [],
            'clientUserOptions' => $this->user()->isSuperAdmin() ? $this->app->make(UserService::class)->clientUsers() : [],
        ]);
    }

    public function edit(int $id): string
    {
        return $this->view('clients/form', ['title' => 'Edit client', 'client' => $this->svc()->find($this->user(), $id)]);
    }

    public function update(int $id): Response
    {
        $this->svc()->save($this->user(), $id, $this->req->all());
        $this->flash('success', 'Client updated.');
        return $this->redirect('/clients/' . $id);
    }

    public function attachUser(int $id): Response
    {
        $this->svc()->find($this->user(), $id);
        $this->svc()->attachUser($this->user(), $id, (int)$this->req->inputInt('user_id'), (bool)$this->req->input('can_approve'), (bool)$this->req->input('can_export'));
        $this->flash('success', 'Client user access saved.');
        return $this->redirect('/clients/' . $id);
    }

    public function detachUser(int $id, int $uid): Response
    {
        $this->svc()->detachUser($this->user(), $id, $uid);
        $this->flash('success', 'User removed from client.');
        return $this->redirect('/clients/' . $id);
    }
}
