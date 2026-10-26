<?php
declare(strict_types=1);

namespace Flatmates\Controllers;

use Flatmates\Http\Response;
use Flatmates\Services\ChatService;
use Flatmates\Services\Notifier;

final class ChatController extends Controller
{
    private function chat(): ChatService
    {
        return $this->app->make(ChatService::class);
    }

    public function inbox(): Response
    {
        return $this->view('chat/inbox', ['title' => 'Messages', 'conversations' => $this->chat()->inbox($this->user()), 'noindex' => true]);
    }

    public function show(int $id): Response
    {
        $c = $this->chat()->conversationFor($this->user(), $id);
        $messages = $this->chat()->messages($this->user(), $id);
        return $this->view('chat/show', ['title' => 'Chat with ' . $c['other_name'], 'c' => $c, 'messages' => $messages, 'noindex' => true, 'bodyClass' => 'chat-page']);
    }

    public function messages(int $id): Response
    {
        $since = (int)($this->req->queryInt('since') ?? 0);
        $before = (int)($this->req->queryInt('before') ?? 0);
        $c = $this->chat()->conversationFor($this->user(), $id);
        return $this->json(['messages' => $this->chat()->messages($this->user(), $id, $since, $before), 'can_send' => $c['can_send'], 'problem' => $c['send_problem']]);
    }

    public function send(int $id): Response
    {
        return $this->json(['message' => $this->chat()->send($this->user(), $id, (string)$this->req->input('body', ''))]);
    }

    /** Lightweight badge counters for the nav (polled every ~25 s). */
    public function poll(): Response
    {
        $u = $this->user();
        return $this->json(['messages' => $this->chat()->unreadTotal($u->id), 'notifications' => $this->app->make(Notifier::class)->unread($u->id)]);
    }
}
