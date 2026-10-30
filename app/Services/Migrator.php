<?php
declare(strict_types=1);

namespace Flatmates\Services;

use Flatmates\Core\App;

/** Applies database/migrations/NNN_name.sql files that are not yet recorded in schema_migrations. */
final class Migrator
{
    public function __construct(private App $app)
    {
    }

    /** @return array<string,?string> version => applied_at (null = pending) */
    public function status(): array
    {
        $applied = $this->app->db()->fetchPairs('SELECT version, applied_at FROM schema_migrations');
        $out = [];
        foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $f) {
            $v = basename($f, '.sql');
            $out[$v] = $applied[$v] ?? null;
        }
        ksort($out);
        return $out;
    }

    /** @return string[] versions applied now */
    public function migrate(): array
    {
        $done = [];
        foreach ($this->status() as $version => $at) {
            if ($at !== null) {
                continue;
            }
            Installer::runSql($this->app->db(), APP_ROOT . '/database/migrations/' . $version . '.sql');
            $this->app->db()->run('INSERT IGNORE INTO schema_migrations (version) VALUES (?)', [$version]);
            $this->app->make(AuditLog::class)->log('system.migrated', null, null, null, ['version' => $version]);
            $done[] = $version;
        }
        return $done;
    }
}
