<?php
declare(strict_types=1);

namespace BharatSeo\Security;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Core\HttpException;

/**
 * Server-side authorization. Every project-scoped query must be filtered with
 * scope() or guarded with authorizeProject(). Unauthorized access to an
 * existing record is reported as 404 to avoid leaking record existence.
 */
final class Gate
{
    public const VIEW = 'view';
    public const MANAGE = 'manage';
    public const APPROVE_CLIENT = 'approve_client';
    public const EXPORT = 'export';

    private Database $db;
    /** @var array<int, array<int,array{client_id:int,member_role:?string,can_approve:int,can_export:int}>> */
    private array $cache = [];

    public function __construct(App $app)
    {
        $this->db = $app->db();
    }

    /**
     * Map of accessible project id => access info. Null means unrestricted (super admin).
     * @return array<int,array{client_id:int,member_role:?string,can_approve:int,can_export:int}>|null
     */
    public function accessMap(User $user): ?array
    {
        if ($user->isSuperAdmin()) {
            return null;
        }
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }
        $map = [];
        if ($user->isManager()) {
            $rows = $this->db->fetchAll(
                'SELECT p.id, p.client_id, pm.role AS member_role FROM project_members pm JOIN projects p ON p.id = pm.project_id WHERE pm.user_id = ?',
                [$user->id]
            );
            foreach ($rows as $r) {
                $map[(int)$r['id']] = ['client_id' => (int)$r['client_id'], 'member_role' => $r['member_role'], 'can_approve' => 0, 'can_export' => 1];
            }
        } elseif ($user->isClient()) {
            $rows = $this->db->fetchAll(
                "SELECT p.id, p.client_id, cu.can_approve, cu.can_export FROM client_users cu
                 JOIN clients c ON c.id = cu.client_id AND c.status = 'active'
                 JOIN projects p ON p.client_id = cu.client_id AND p.status <> 'archived'
                 WHERE cu.user_id = ?",
                [$user->id]
            );
            foreach ($rows as $r) {
                $map[(int)$r['id']] = ['client_id' => (int)$r['client_id'], 'member_role' => null, 'can_approve' => (int)$r['can_approve'], 'can_export' => (int)$r['can_export']];
            }
        }
        return $this->cache[$user->id] = $map;
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /** @return int[]|null */
    public function projectIds(User $user): ?array
    {
        $map = $this->accessMap($user);
        return $map === null ? null : array_keys($map);
    }

    /** @return int[]|null */
    public function clientIds(User $user): ?array
    {
        $map = $this->accessMap($user);
        return $map === null ? null : array_values(array_unique(array_column($map, 'client_id')));
    }

    /**
     * SQL fragment restricting $column to accessible projects.
     * @return array{0:string,1:array}
     */
    public function scope(User $user, string $column = 'project_id'): array
    {
        if (!preg_match('/^[a-z_]+(\.[a-z_]+)?$/', $column)) {
            throw new \InvalidArgumentException('Invalid scope column');
        }
        $ids = $this->projectIds($user);
        if ($ids === null) {
            return ['', []];
        }
        if ($ids === []) {
            return [' AND 1 = 0', []];
        }
        return [" AND $column IN (" . Database::in($ids) . ')', $ids];
    }

    public function can(User $user, string $ability, int $projectId): bool
    {
        if ($user->status !== 'active') {
            return false;
        }
        $map = $this->accessMap($user);
        if ($map === null) {
            return $ability !== self::APPROVE_CLIENT; // super admins approve as agency, not as the client
        }
        if (!isset($map[$projectId])) {
            return false;
        }
        $a = $map[$projectId];
        return match ($ability) {
            self::VIEW => true,
            self::MANAGE => $user->isManager() && $a['member_role'] === 'manager',
            self::APPROVE_CLIENT => $user->isClient() && $a['can_approve'] === 1,
            self::EXPORT => $user->isManager() || $a['can_export'] === 1,
            default => false,
        };
    }

    /** Throw 404 when the user cannot even view the project, 403 when the ability is missing. */
    public function authorizeProject(User $user, int $projectId, string $ability = self::VIEW): void
    {
        if (!$this->can($user, self::VIEW, $projectId)) {
            throw new HttpException(404);
        }
        if ($ability !== self::VIEW && !$this->can($user, $ability, $projectId)) {
            throw new HttpException(403);
        }
    }

    public function requireRole(User $user, string ...$roles): void
    {
        if (!in_array($user->role, $roles, true)) {
            throw new HttpException(403);
        }
    }

    public function canAccessClient(User $user, int $clientId): bool
    {
        $ids = $this->clientIds($user);
        return $ids === null || in_array($clientId, $ids, true);
    }
}
