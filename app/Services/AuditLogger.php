<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Core\Logger;

/**
 * Append-only, hash-chained audit log. The application never updates or deletes
 * audit rows; verifyChain() detects tampering. Optional DB triggers
 * (database/optional_audit_triggers.sql) block UPDATE/DELETE at the database level.
 */
final class AuditLogger
{
    private Database $db;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    public function log(string $action, ?int $userId, ?int $projectId = null, array $details = [], ?string $entityType = null, ?string $ip = null, ?int $entityId = null, ?int $clientId = null): void
    {
        $details = Logger::redact($details);
        if ($projectId !== null && $clientId === null) {
            $clientId = (int)($this->db->fetchColumn('SELECT client_id FROM projects WHERE id = ?', [$projectId]) ?? 0) ?: null;
        }
        $ip ??= $_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'cli' : null);
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : (PHP_SAPI === 'cli' ? 'cli' : null);
        $detailsJson = $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
        $createdAt = gmdate('Y-m-d H:i:s');

        $this->db->transaction(function (Database $db) use ($action, $userId, $projectId, $clientId, $entityType, $entityId, $detailsJson, $ip, $ua, $createdAt): void {
            // Serialize writers so the chain stays linear.
            $db->fetchColumn('SELECT GET_LOCK(\'bharatseo_audit\', 5)');
            try {
                $prev = (string)($db->fetchColumn('SELECT entry_hash FROM audit_logs ORDER BY id DESC LIMIT 1') ?? str_repeat('0', 64));
                $hash = self::computeHash($prev, $createdAt, $action, $userId, $projectId, $entityType, $entityId, $detailsJson);
                $db->insert('audit_logs', [
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'project_id' => $projectId,
                    'action' => mb_substr($action, 0, 80),
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'details' => $detailsJson,
                    'ip_address' => $ip !== null ? mb_substr($ip, 0, 45) : null,
                    'user_agent' => $ua,
                    'prev_hash' => $prev,
                    'entry_hash' => $hash,
                    'created_at' => $createdAt,
                ]);
            } finally {
                $db->fetchColumn('SELECT RELEASE_LOCK(\'bharatseo_audit\')');
            }
        });
    }

    public static function computeHash(string $prev, string $createdAt, string $action, ?int $userId, ?int $projectId, ?string $entityType, ?int $entityId, ?string $details): string
    {
        return hash('sha256', implode('|', [$prev, $createdAt, $action, (string)$userId, (string)$projectId, (string)$entityType, (string)$entityId, (string)$details]));
    }

    /** @return array{ok:bool, checked:int, broken_at:?int} */
    public function verifyChain(int $limit = 100000): array
    {
        $prev = str_repeat('0', 64);
        $checked = 0;
        $stmt = $this->db->run('SELECT * FROM audit_logs ORDER BY id ASC LIMIT ' . max(1, $limit));
        while ($r = $stmt->fetch()) {
            $expected = self::computeHash($prev, $r['created_at'], $r['action'], $r['user_id'] !== null ? (int)$r['user_id'] : null, $r['project_id'] !== null ? (int)$r['project_id'] : null, $r['entity_type'], $r['entity_id'] !== null ? (int)$r['entity_id'] : null, $r['details']);
            if ($r['prev_hash'] !== $prev || $r['entry_hash'] !== $expected) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int)$r['id']];
            }
            $prev = $r['entry_hash'];
            $checked++;
        }
        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }
}
