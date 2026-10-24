<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Core\HttpException;
use BharatSeo\Core\ValidationException;
use BharatSeo\Security\Gate;
use BharatSeo\Security\User;
use BharatSeo\Support\Validator;

final class ClientService
{
    private Database $db;
    private Gate $gate;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
        $this->gate = $app->make(Gate::class);
    }

    public function list(User $user): array
    {
        $ids = $this->gate->clientIds($user);
        $where = '';
        $params = [];
        if ($ids !== null) {
            $where = ' WHERE c.id IN (' . Database::in($ids) . ')';
            $params = $ids;
        }
        return $this->db->fetchAll(
            "SELECT c.*, (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id) AS project_count,
                    (SELECT COUNT(*) FROM client_users cu WHERE cu.client_id = c.id) AS user_count
             FROM clients c$where ORDER BY c.status = 'active' DESC, c.name",
            $params
        );
    }

    public function find(User $user, int $id): array
    {
        if (!$this->gate->canAccessClient($user, $id)) {
            throw new HttpException(404);
        }
        $c = $this->db->fetch('SELECT * FROM clients WHERE id = ?', [$id]);
        if (!$c) {
            throw new HttpException(404);
        }
        return $c;
    }

    private function validate(array $input): array
    {
        return Validator::make($input)
            ->string('name', 'Client name', true, 160, 2)
            ->string('company', 'Company', false, 190)
            ->email('contact_email', 'Contact email', false)
            ->string('phone', 'Phone', false, 40)
            ->text('notes', 'Notes', false, 5000)
            ->in('status', 'Status', ['active', 'inactive'], true, 'active')
            ->validated();
    }

    public function save(User $user, ?int $id, array $input): int
    {
        $this->gate->requireRole($user, User::SUPER_ADMIN);
        $d = $this->validate($input);
        try {
            if ($id === null) {
                $id = $this->db->insert('clients', $d + ['created_by' => $user->id]);
                $action = 'client.created';
            } else {
                $this->find($user, $id);
                $this->db->update('clients', $d, 'id = ?', [$id]);
                $action = 'client.updated';
            }
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw ValidationException::single('name', 'A client with this name already exists.');
            }
            throw $e;
        }
        $this->gate->flush();
        $this->app->make(AuditLogger::class)->log($action, $user->id, null, ['name' => $d['name']], 'client', null, $id, $id);
        return $id;
    }

    public function users(int $clientId): array
    {
        return $this->db->fetchAll('SELECT cu.*, u.name, u.email, u.status FROM client_users cu JOIN users u ON u.id = cu.user_id WHERE cu.client_id = ? ORDER BY u.name', [$clientId]);
    }

    public function attachUser(User $actor, int $clientId, int $userId, bool $canApprove, bool $canExport): void
    {
        $this->gate->requireRole($actor, User::SUPER_ADMIN);
        $u = $this->db->fetch("SELECT id FROM users WHERE id = ? AND role = 'client'", [$userId]);
        if (!$u) {
            throw ValidationException::single('user_id', 'Only users with the Client role can be attached to a client.');
        }
        $this->db->run(
            'INSERT INTO client_users (client_id, user_id, can_approve, can_export) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE can_approve = VALUES(can_approve), can_export = VALUES(can_export)',
            [$clientId, $userId, (int)$canApprove, (int)$canExport]
        );
        $this->gate->flush();
        $this->app->make(AuditLogger::class)->log('client.user_attached', $actor->id, null, ['user_id' => $userId, 'can_approve' => $canApprove, 'can_export' => $canExport], 'client', null, $clientId, $clientId);
    }

    public function detachUser(User $actor, int $clientId, int $userId): void
    {
        $this->gate->requireRole($actor, User::SUPER_ADMIN);
        $this->db->run('DELETE FROM client_users WHERE client_id = ? AND user_id = ?', [$clientId, $userId]);
        $this->gate->flush();
        $this->app->make(AuditLogger::class)->log('client.user_detached', $actor->id, null, ['user_id' => $userId], 'client', null, $clientId, $clientId);
    }

    /**
     * Give a person access to this client's projects: attach an existing Client-role user,
     * or create a new Client-role account first. Returns the user id.
     */
    public function grantAccess(User $actor, int $clientId, array $input): int
    {
        $this->gate->requireRole($actor, User::SUPER_ADMIN);
        $this->find($actor, $clientId);
        $canApprove = !empty($input['can_approve']);
        $canExport = !empty($input['can_export']);
        $userId = (int)($input['user_id'] ?? 0);
        if ($userId <= 0) {
            $email = strtolower(trim((string)($input['email'] ?? '')));
            $existing = $this->db->fetch('SELECT id, role FROM users WHERE email = ?', [$email]);
            if ($existing && $existing['role'] !== User::CLIENT) {
                throw ValidationException::single('email', 'That email belongs to a staff account; client access needs a Client-role user.');
            }
            $userId = $existing ? (int)$existing['id'] : $this->app->make(UserService::class)->save($actor, null, [
                'name' => $input['name'] ?? '', 'email' => $email, 'role' => User::CLIENT, 'status' => 'active', 'password' => $input['password'] ?? '',
            ]);
        }
        $this->attachUser($actor, $clientId, $userId, $canApprove, $canExport);
        return $userId;
    }
}
