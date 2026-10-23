<?php
declare(strict_types=1);

namespace BharatSeo\Security;

/** Authenticated user value object. */
final class User
{
    public const SUPER_ADMIN = 'super_admin';
    public const SEO_MANAGER = 'seo_manager';
    public const CLIENT = 'client';

    public const ROLES = [
        self::SUPER_ADMIN => 'Super Admin',
        self::SEO_MANAGER => 'SEO Manager',
        self::CLIENT => 'Client',
    ];

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role,
        public readonly string $status = 'active',
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self((int)$row['id'], (string)$row['name'], (string)$row['email'], (string)$row['role'], (string)($row['status'] ?? 'active'));
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::SUPER_ADMIN;
    }

    public function isManager(): bool
    {
        return $this->role === self::SEO_MANAGER;
    }

    public function isClient(): bool
    {
        return $this->role === self::CLIENT;
    }

    /** Super admin or SEO manager (agency staff). */
    public function isStaff(): bool
    {
        return $this->isSuperAdmin() || $this->isManager();
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }
}
