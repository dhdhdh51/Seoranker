<?php
declare(strict_types=1);

namespace Flatmates\Security;

final class User
{
    public const STUDENT = 'student';
    public const MODERATOR = 'moderator';
    public const SUPER_ADMIN = 'super_admin';

    /** Moderator permission flags (assigned by Super Admins). */
    public const PERMISSIONS = [
        'reports' => 'Review reports',
        'listings' => 'Moderate listings & photos',
        'verifications' => 'Review student verifications (can view documents)',
        'users' => 'Restrict / suspend students',
    ];

    /** @param string[] $permissions */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role,
        public readonly string $status,
        public readonly array $permissions = [],
        public readonly ?string $emailVerifiedAt = null,
    ) {
    }

    public static function fromRow(array $r): self
    {
        $perms = array_values(array_intersect(array_keys(self::PERMISSIONS), explode(',', (string)($r['permissions'] ?? ''))));
        return new self((int)$r['id'], (string)$r['name'], (string)$r['email'], (string)$r['role'], (string)$r['status'], $perms, $r['email_verified_at'] ?? null);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::SUPER_ADMIN;
    }

    public function isStaff(): bool
    {
        return $this->role === self::SUPER_ADMIN || $this->role === self::MODERATOR;
    }

    public function isStudent(): bool
    {
        return $this->role === self::STUDENT;
    }

    /** Super admins have every permission; moderators only those granted. */
    public function can(string $permission): bool
    {
        return $this->isAdmin() || ($this->role === self::MODERATOR && in_array($permission, $this->permissions, true));
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'restricted'], true);
    }

    /** Restricted accounts can browse but not message, swipe, publish or enquire. */
    public function canInteract(): bool
    {
        return $this->status === 'active';
    }
}
