<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;

/** In-app notifications. */
final class Notifier
{
    public function __construct(private App $app)
    {
    }

    public function notify(int $userId, string $type, string $title, ?string $link = null): void
    {
        $this->app->db()->insert('notifications', ['user_id' => $userId, 'type' => $type, 'title' => mb_substr($title, 0, 190), 'link' => $link]);
    }

    public function notifyStaff(string $permission, string $title, ?string $link = null): void
    {
        foreach ($this->app->db()->fetchAll("SELECT id, role, permissions FROM users WHERE role IN ('super_admin','moderator') AND status = 'active'") as $u) {
            if ($u['role'] === 'super_admin' || in_array($permission, explode(',', (string)$u['permissions']), true)) {
                $this->notify((int)$u['id'], 'staff', $title, $link);
            }
        }
    }

    public function unread(int $userId): int
    {
        return (int)$this->app->db()->fetchColumn('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]);
    }
}
