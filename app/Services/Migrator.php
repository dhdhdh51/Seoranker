<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;

/**
 * Applies versioned SQL migrations from database/migrations (NNN_description.sql)
 * and records them in schema_migrations. Used by UPGRADE.md, bin/migrate.php and the
 * Installation & Upgrade screen.
 */
final class Migrator
{
    public function __construct(private App $app)
    {
    }

    public static function splitSql(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(\r?\n|$)/', $sql) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn($s) => $s !== ''));
    }

    public static function runSchema(Database $db, string $file): int
    {
        $n = 0;
        foreach (self::splitSql((string)file_get_contents($file)) as $stmt) {
            $db->pdo()->exec($stmt);
            $n++;
        }
        return $n;
    }

    /** @return array<string,array{file:string, applied_at:?string}> */
    public function status(): array
    {
        $applied = [];
        try {
            $applied = $this->app->db()->fetchPairs('SELECT version, applied_at FROM schema_migrations');
        } catch (\Throwable) {
        }
        $out = [];
        foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $file) {
            $v = basename($file, '.sql');
            $out[$v] = ['file' => basename($file), 'applied_at' => $applied[$v] ?? null];
        }
        foreach ($applied as $v => $at) {
            $out[$v] ??= ['file' => '(built into schema.sql)', 'applied_at' => $at];
        }
        ksort($out);
        return $out;
    }

    /** @return string[] versions applied */
    public function migrate(?int $userId = null): array
    {
        $done = [];
        foreach ($this->status() as $version => $info) {
            if ($info['applied_at'] !== null || !preg_match('/^\d{3}_[a-z0-9_]+$/', $version)) {
                continue;
            }
            $file = APP_ROOT . '/database/migrations/' . $version . '.sql';
            self::runSchema($this->app->db(), $file);
            $this->app->db()->run('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
            $this->app->make(AuditLogger::class)->log('system.migration_applied', $userId, null, ['version' => $version]);
            $done[] = $version;
        }
        return $done;
    }
}
