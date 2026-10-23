<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;

/**
 * Pure-PHP logical backup/restore (works on shared hosting without mysqldump).
 * Backups are gzip-compressed SQL written to storage/backups (not web-accessible).
 * For large databases prefer cPanel "Backup" or mysqldump (see SECURITY.md / UPGRADE.md).
 */
final class BackupService
{
    public function __construct(private App $app)
    {
    }

    public function backup(?string $file = null): string
    {
        $db = $this->app->db();
        $file ??= $this->app->config->storagePath('backups/bharatseo-' . gmdate('Ymd-His') . '.sql.gz');
        $gz = gzopen($file, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Cannot write backup file.');
        }
        $pdo = $db->pdo();
        gzwrite($gz, "-- Bharat SEO backup " . gmdate('c') . " (app " . APP_VERSION . ")\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            Database::assertIdentifier($table);
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(\PDO::FETCH_NUM)[1];
            gzwrite($gz, "\nDROP TABLE IF EXISTS `$table`;\n$create;\n");
            $stmt = $pdo->query("SELECT * FROM `$table`", \PDO::FETCH_ASSOC);
            $batch = [];
            $cols = null;
            while ($row = $stmt->fetch()) {
                $cols ??= '`' . implode('`,`', array_keys($row)) . '`';
                $batch[] = '(' . implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
                if (count($batch) >= 200) {
                    gzwrite($gz, "INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, "INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        @chmod($file, 0640);
        return $file;
    }

    /** Restore a backup produced by backup(). Destroys current data in the same tables. */
    public function restore(string $file): int
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Backup file not found.');
        }
        $pdo = $this->app->db()->pdo();
        $gz = gzopen($file, 'rb');
        $buffer = '';
        $count = 0;
        $inString = false;
        while (!gzeof($gz)) {
            $line = (string)gzgets($gz, 1 << 20);
            if (!$inString && (str_starts_with($line, '-- ') || trim($line) === '')) {
                continue;
            }
            $buffer .= $line;
            // Track quote state so ";\n" inside quoted values does not end a statement.
            $inString = self::endsInsideString($buffer);
            if (!$inString && preg_match('/;\s*$/', $line)) {
                $pdo->exec($buffer);
                $buffer = '';
                $count++;
            }
        }
        gzclose($gz);
        if (trim($buffer) !== '') {
            $pdo->exec($buffer);
            $count++;
        }
        return $count;
    }

    private static function endsInsideString(string $sql): bool
    {
        $in = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($c === '\\' && $in) {
                $i++;
                continue;
            }
            if ($c === "'") {
                if ($in && $i + 1 < $len && $sql[$i + 1] === "'") {
                    $i++;
                    continue;
                }
                $in = !$in;
            }
        }
        return $in;
    }
}
