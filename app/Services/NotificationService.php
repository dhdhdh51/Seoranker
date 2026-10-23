<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;

final class NotificationService
{
    private Database $db;

    public function __construct(App $app)
    {
        $this->db = $app->db();
    }

    public function notify(int $userId, string $type, string $title, ?string $body = null, ?string $link = null, ?int $projectId = null): void
    {
        $this->db->insert('notifications', [
            'user_id' => $userId,
            'project_id' => $projectId,
            'type' => $type,
            'title' => mb_substr($title, 0, 190),
            'body' => $body !== null ? mb_substr($body, 0, 1000) : null,
            'link' => $link,
        ]);
    }

    public function notifyAdmins(string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        foreach ($this->db->fetchAll("SELECT id FROM users WHERE role = 'super_admin' AND status = 'active'") as $u) {
            $this->notify((int)$u['id'], $type, $title, $body, $link);
        }
    }

    /** Notify project managers (and optionally client approvers) of a project. */
    public function notifyProject(int $projectId, string $type, string $title, ?string $body = null, ?string $link = null, bool $includeClientApprovers = false): void
    {
        $ids = $this->db->fetchAll("SELECT pm.user_id FROM project_members pm JOIN users u ON u.id = pm.user_id AND u.status = 'active' WHERE pm.project_id = ? AND pm.role = 'manager'", [$projectId]);
        $users = array_map(static fn($r) => (int)$r['user_id'], $ids);
        if ($includeClientApprovers) {
            $c = $this->db->fetchAll(
                "SELECT cu.user_id FROM client_users cu JOIN projects p ON p.client_id = cu.client_id JOIN users u ON u.id = cu.user_id AND u.status = 'active' WHERE p.id = ? AND cu.can_approve = 1",
                [$projectId]
            );
            $users = array_merge($users, array_map(static fn($r) => (int)$r['user_id'], $c));
        }
        foreach (array_unique($users) as $uid) {
            $this->notify($uid, $type, $title, $body, $link, $projectId);
        }
    }

    public function unreadCount(int $userId): int
    {
        return (int)$this->db->fetchColumn('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]);
    }
}
