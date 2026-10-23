<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Core\HttpException;
use BharatSeo\Core\ValidationException;
use BharatSeo\Security\Auth;
use BharatSeo\Security\Gate;
use BharatSeo\Security\User;
use BharatSeo\Support\Validator;

final class UserService
{
    private Database $db;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    public function list(): array
    {
        return $this->db->fetchAll(
            "SELECT u.id, u.name, u.email, u.role, u.status, u.last_login_at, u.created_at,
                    (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') FROM client_users cu JOIN clients c ON c.id = cu.client_id WHERE cu.user_id = u.id) AS clients,
                    (SELECT COUNT(*) FROM project_members pm WHERE pm.user_id = u.id) AS project_count
             FROM users u ORDER BY u.role, u.name"
        );
    }

    public function find(int $id): array
    {
        $u = $this->db->fetch('SELECT id, name, email, role, status, last_login_at, created_at FROM users WHERE id = ?', [$id]);
        if (!$u) {
            throw new HttpException(404);
        }
        return $u;
    }

    public function save(User $actor, ?int $id, array $input): int
    {
        $this->app->make(Gate::class)->requireRole($actor, User::SUPER_ADMIN);
        $v = Validator::make($input)
            ->string('name', 'Name', true, 120, 2)
            ->email('email', 'Email')
            ->in('role', 'Role', array_keys(User::ROLES))
            ->in('status', 'Status', ['active', 'disabled'], true, 'active');
        $password = (string)($input['password'] ?? '');
        if ($id === null || $password !== '') {
            foreach (Auth::passwordProblems($password, (string)($input['email'] ?? '')) as $p) {
                $v->addError('password', $p);
                break;
            }
        }
        $d = $v->validated();
        if ($id !== null && $id === $actor->id && ($d['role'] !== User::SUPER_ADMIN || $d['status'] !== 'active')) {
            throw ValidationException::single('role', 'You cannot demote or disable your own account.');
        }
        if ($id !== null && $d['role'] !== User::SUPER_ADMIN) {
            $current = $this->find($id);
            if ($current['role'] === User::SUPER_ADMIN && (int)$this->db->fetchColumn("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'active'") <= 1) {
                throw ValidationException::single('role', 'At least one active Super Admin is required.');
            }
        }
        $isNew = $id === null;
        $row = ['name' => $d['name'], 'email' => $d['email'], 'role' => $d['role'], 'status' => $d['status']];
        if ($password !== '') {
            $row['password_hash'] = Auth::hashPassword($password);
            $row['password_changed_at'] = gmdate('Y-m-d H:i:s');
        }
        try {
            $this->db->transaction(function (Database $db) use (&$id, $row): void {
                if ($id === null) {
                    $id = $db->insert('users', $row);
                } else {
                    $old = $db->fetch('SELECT role FROM users WHERE id = ?', [$id]);
                    $db->update('users', $row, 'id = ?', [$id]);
                    // Role changes revoke memberships that no longer make sense.
                    if ($old && $old['role'] !== $row['role']) {
                        if ($row['role'] !== User::CLIENT) {
                            $db->run('DELETE FROM client_users WHERE user_id = ?', [$id]);
                        }
                        if ($row['role'] !== User::SEO_MANAGER) {
                            $db->run('DELETE FROM project_members WHERE user_id = ?', [$id]);
                        }
                    }
                }
            });
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw ValidationException::single('email', 'A user with this email already exists.');
            }
            throw $e;
        }
        $this->app->make(Gate::class)->flush();
        $this->app->make(AuditLogger::class)->log($isNew ? 'user.created' : 'user.updated', $actor->id, null, ['user_id' => $id, 'role' => $d['role'], 'status' => $d['status'], 'password_changed' => $password !== ''], 'user', null, $id);
        return (int)$id;
    }

    public function changeOwnPassword(User $user, string $current, string $new): array
    {
        $row = $this->db->fetch('SELECT password_hash, email FROM users WHERE id = ?', [$user->id]);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            return ['Current password is incorrect.'];
        }
        $problems = Auth::passwordProblems($new, $row['email']);
        if ($problems) {
            return $problems;
        }
        $hash = Auth::hashPassword($new);
        $this->db->update('users', ['password_hash' => $hash, 'password_changed_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$user->id]);
        $_SESSION['pwd_marker'] = substr(hash('sha256', $hash), 0, 16);
        $this->app->make(AuditLogger::class)->log('user.password_changed', $user->id);
        return [];
    }

    public function managers(): array
    {
        return $this->db->fetchPairs("SELECT id, CONCAT(name, ' <', email, '>') FROM users WHERE role = 'seo_manager' AND status = 'active' ORDER BY name");
    }

    public function clientUsers(): array
    {
        return $this->db->fetchPairs("SELECT id, CONCAT(name, ' <', email, '>') FROM users WHERE role = 'client' AND status = 'active' ORDER BY name");
    }
}
