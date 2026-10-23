<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Core\App;
use BharatSeo\Core\HttpException;
use BharatSeo\Http\Kernel;
use BharatSeo\Http\Request;
use BharatSeo\Http\Response;
use BharatSeo\Http\View;
use BharatSeo\Security\Gate;
use BharatSeo\Security\Session;
use BharatSeo\Security\User;
use BharatSeo\Services\ProjectService;

abstract class Controller
{
    protected Gate $gate;

    public function __construct(protected App $app, protected Request $req, protected ?User $user)
    {
        $this->gate = $app->make(Gate::class);
    }

    protected function user(): User
    {
        if ($this->user === null) {
            throw new HttpException(403);
        }
        return $this->user;
    }

    protected function view(string $template, array $data = []): string
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        return View::render($template, $data + [
            'flash' => Session::pullFlash(),
            'errors' => $errors,
            'title' => $data['title'] ?? 'Bharat SEO',
        ]);
    }

    protected function redirect(string $path, array $query = []): Response
    {
        return Response::redirect(url($path, $query));
    }

    protected function back(): Response
    {
        return Response::redirect(Kernel::backUrl($this->req));
    }

    protected function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    protected function projectOptions(): array
    {
        return $this->app->make(ProjectService::class)->options($this->user());
    }

    /** Validated project filter from the query string (must be accessible). */
    protected function projectFilter(): ?int
    {
        $id = $this->req->queryInt('project_id');
        if ($id !== null && !$this->gate->can($this->user(), Gate::VIEW, $id)) {
            throw new HttpException(404);
        }
        return $id;
    }

    protected function page(): int
    {
        return max(1, (int)($this->req->queryInt('page') ?? 1));
    }
}
