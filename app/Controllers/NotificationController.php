<?php
declare(strict_types=1);

namespace BharatSeo\Controllers;

use BharatSeo\Http\Response;

final class NotificationController extends Controller
{
    public function index(): string
    {
        $rows = $this->app->db()->fetchAll('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 200', [$this->user()->id]);
        return $this->view('notifications/index', ['title' => 'Notifications', 'rows' => $rows]);
    }

    public function readAll(): Response
    {
        $this->app->db()->run('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$this->user()->id]);
        return $this->redirect('/notifications');
    }

    public function read(int $id): Response
    {
        $n = $this->app->db()->fetch('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $this->user()->id]);
        if ($n) {
            $this->app->db()->run('UPDATE notifications SET is_read = 1 WHERE id = ?', [$id]);
            $link = (string)$n['link'];
            if (str_starts_with($link, '/') && !str_starts_with($link, '//')) {
                return Response::redirect($link);
            }
        }
        return $this->redirect('/notifications');
    }
}
