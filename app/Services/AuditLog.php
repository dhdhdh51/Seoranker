<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;
use Flatmates\Core\Logger;

/** Append-only security & moderation log. Secrets are redacted before storage. */
final class AuditLog
{
    public function __construct(private App $app)
    {
    }

    public function log(string $action, ?int $actorId, ?string $targetType = null, ?int $targetId = null, array $details = []): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'cli' : null);
        $this->app->db()->insert('audit_logs', [
            'actor_id' => $actorId,
            'action' => mb_substr($action, 0, 80),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $details ? json_encode(Logger::redact($details), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ip_address' => $ip !== null ? mb_substr((string)$ip, 0, 45) : null,
        ]);
    }
}
