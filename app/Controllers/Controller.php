<?php
declare(strict_types=1);

namespace Flatmates\Controllers;

use Flatmates\Core\App;
use Flatmates\Core\HttpException;
use Flatmates\Http\Kernel;
use Flatmates\Http\Request;
use Flatmates\Http\Response;
use Flatmates\Http\View;
use Flatmates\Security\Session;
use Flatmates\Security\User;

abstract class Controller
{
    public function __construct(protected App $app, protected Request $req, protected ?User $currentUser)
    {
    }

    protected function user(): User
    {
        if (!$this->currentUser) {
            throw new HttpException(401);
        }
        return $this->currentUser;
    }

    protected function view(string $template, array $data = [], string $layout = 'layouts/app'): Response
    {
        $data['errors'] = $_SESSION['_errors'] ?? [];
        $data['flash'] = session_status() === PHP_SESSION_ACTIVE ? Session::pullFlash() : [];
        return Response::html(View::render($template, $data, $layout));
    }

    protected function redirect(string $path, array $query = []): Response
    {
        return Response::redirect(url($path, $query));
    }

    protected function back(): Response
    {
        return Response::redirect(Kernel::backUrl($this->req));
    }

    protected function flash(string $type, string $msg): void
    {
        Session::flash($type, $msg);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function page(): int
    {
        return max(1, min(1000, (int)($this->req->queryInt('page') ?? 1)));
    }
}
