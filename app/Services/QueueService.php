<?php
declare(strict_types=1);

namespace BharatSeo\Services;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;
use BharatSeo\Jobs\JobHandler;
use BharatSeo\Jobs\PermanentException;
use BharatSeo\Jobs\RetryableException;

/**
 * MySQL-backed job queue with atomic reservation, lock expiry, retries with
 * exponential backoff + jitter, max attempts, failed-job archive and a global pause.
 */
final class QueueService
{
    public const LOCK_SECONDS = 300;
    public const BACKOFF_BASE_SECONDS = 60;
    public const BACKOFF_MAX_SECONDS = 21600;

    /** type => handler class */
    public const HANDLERS = [
        'evaluate_prospect' => \BharatSeo\Jobs\EvaluateProspectJob::class,
        'process_import' => \BharatSeo\Jobs\ProcessImportJob::class,
        'search_discovery' => \BharatSeo\Jobs\SearchDiscoveryJob::class,
        'extract_public_page' => \BharatSeo\Jobs\ExtractPublicPageJob::class,
        'scan_broken_links' => \BharatSeo\Jobs\ScanBrokenLinksJob::class,
        'check_backlink' => \BharatSeo\Jobs\CheckBacklinkJob::class,
        'generate_draft' => \BharatSeo\Jobs\GenerateDraftJob::class,
    ];

    private Database $db;

    public function __construct(private App $app)
    {
        $this->db = $app->db();
    }

    /**
     * Enqueue a job. When $uniqueKey is given and an active (pending/running) job already has it,
     * nothing is inserted and the existing id is returned (idempotent enqueue).
     */
    public function push(string $type, array $payload, ?int $projectId = null, ?string $uniqueKey = null, int $delaySeconds = 0, int $priority = 5, ?int $maxAttempts = null): int
    {
        if (!isset(self::HANDLERS[$type])) {
            throw new \InvalidArgumentException("Unknown job type: $type");
        }
        $maxAttempts ??= (int)$this->app->settings()->get('queue.default_max_attempts', 5);
        $this->db->run(
            'INSERT IGNORE INTO job_queue (type, payload, project_id, status, priority, attempts, max_attempts, unique_key, available_at)
             VALUES (?, ?, ?, \'pending\', ?, 0, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND)',
            [$type, json_encode($payload, JSON_UNESCAPED_SLASHES), $projectId, max(1, min(9, $priority)), max(1, $maxAttempts), $uniqueKey, max(0, $delaySeconds)]
        );
        $id = $this->db->lastInsertId();
        if ($id === 0 && $uniqueKey !== null) {
            $id = (int)$this->db->fetchColumn('SELECT id FROM job_queue WHERE unique_key = ?', [$uniqueKey]);
        }
        return $id;
    }

    public function isPaused(): bool
    {
        $this->app->settings()->refresh();
        return $this->app->settings()->bool('queue.paused');
    }

    /** Return expired locks to the queue (or fail them when out of attempts). */
    public function recoverStale(): int
    {
        $rows = $this->db->fetchAll("SELECT * FROM job_queue WHERE status = 'running' AND lock_expires_at < UTC_TIMESTAMP() LIMIT 100");
        foreach ($rows as $job) {
            $this->fail($job, 'Worker lock expired (worker crashed or exceeded its time limit).', true);
        }
        return count($rows);
    }

    /**
     * Atomically reserve the next due job. Uses a single UPDATE … ORDER BY … LIMIT 1 with a random
     * reservation token so two concurrent workers can never get the same job (works on MySQL 5.7+/8 and MariaDB).
     * @param string[] $types optional type filter
     */
    public function reserve(string $workerId, array $types = []): ?array
    {
        $token = bin2hex(random_bytes(16));
        $typeSql = '';
        $params = [$token, mb_substr($workerId, 0, 100), self::LOCK_SECONDS];
        if ($types) {
            $typeSql = ' AND type IN (' . Database::in($types) . ')';
            $params = array_merge($params, $types);
        }
        $n = $this->db->run(
            "UPDATE job_queue SET status = 'running', reserved_token = ?, locked_by = ?, locked_at = UTC_TIMESTAMP(),
                    lock_expires_at = UTC_TIMESTAMP() + INTERVAL ? SECOND, attempts = attempts + 1
             WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP()$typeSql
             ORDER BY priority ASC, available_at ASC, id ASC LIMIT 1",
            $params
        )->rowCount();
        if ($n === 0) {
            return null;
        }
        $job = $this->db->fetch('SELECT * FROM job_queue WHERE reserved_token = ?', [$token]);
        if ($job) {
            $job['data'] = json_decode((string)$job['payload'], true) ?: [];
        }
        return $job;
    }

    public function complete(array $job): void
    {
        $this->db->run(
            "UPDATE job_queue SET status = 'completed', completed_at = UTC_TIMESTAMP(), unique_key = NULL, reserved_token = NULL, lock_expires_at = NULL, last_error = NULL WHERE id = ? AND reserved_token <=> ?",
            [$job['id'], $job['reserved_token']]
        );
    }

    public static function backoffSeconds(int $attempts, ?int $hint = null): int
    {
        $delay = (int)min(self::BACKOFF_MAX_SECONDS, self::BACKOFF_BASE_SECONDS * (2 ** max(0, $attempts - 1)));
        $delay += random_int(0, (int)max(1, $delay * 0.1));
        if ($hint !== null) {
            $delay = max($delay, min(self::BACKOFF_MAX_SECONDS, $hint));
        }
        return $delay;
    }

    public function fail(array $job, string $error, bool $retryable = true, ?int $retryAfter = null): void
    {
        $error = mb_substr(\BharatSeo\Core\Logger::redactString($error), 0, 1000);
        $attempts = (int)$job['attempts'];
        if ($retryable && $attempts < (int)$job['max_attempts']) {
            $this->db->run(
                "UPDATE job_queue SET status = 'pending', reserved_token = NULL, locked_by = NULL, locked_at = NULL, lock_expires_at = NULL,
                        last_error = ?, available_at = UTC_TIMESTAMP() + INTERVAL ? SECOND WHERE id = ?",
                [$error, self::backoffSeconds($attempts, $retryAfter), $job['id']]
            );
            return;
        }
        $this->db->transaction(function (Database $db) use ($job, $error, $attempts): void {
            $db->run(
                "UPDATE job_queue SET status = 'failed', unique_key = NULL, reserved_token = NULL, lock_expires_at = NULL, last_error = ?, completed_at = UTC_TIMESTAMP() WHERE id = ?",
                [$error, $job['id']]
            );
            $db->insert('failed_jobs', [
                'job_id' => (int)$job['id'],
                'type' => $job['type'],
                'payload' => $job['payload'],
                'project_id' => $job['project_id'] !== null ? (int)$job['project_id'] : null,
                'error' => $error,
                'attempts' => $attempts,
            ]);
        });
        try {
            $this->app->make(NotificationService::class)->notifyAdmins('job_failed', 'Job failed: ' . $job['type'], $error, url('/system/queue'));
        } catch (\Throwable) {
        }
    }

    /**
     * Process jobs until the time/job budget is exhausted.
     * @return array{processed:int, failed:int, retried:int, paused:bool}
     */
    public function work(string $workerId, int $maxSeconds, int $maxJobs, array $types = []): array
    {
        $stats = ['processed' => 0, 'failed' => 0, 'retried' => 0, 'paused' => false];
        if ($this->isPaused()) {
            $stats['paused'] = true;
            return $stats;
        }
        $this->recoverStale();
        $deadline = microtime(true) + $maxSeconds;
        while ($stats['processed'] < $maxJobs && microtime(true) < $deadline) {
            if ($stats['processed'] > 0 && $stats['processed'] % 5 === 0 && $this->isPaused()) {
                $stats['paused'] = true;
                break;
            }
            $job = $this->reserve($workerId, $types);
            if ($job === null) {
                break;
            }
            $stats['processed']++;
            $result = $this->runJob($job);
            if ($result === 'failed') {
                $stats['failed']++;
            } elseif ($result === 'retry') {
                $stats['retried']++;
            }
            if (memory_get_usage(true) > 0.8 * self::memoryLimitBytes()) {
                break;
            }
        }
        return $stats;
    }

    /** @return 'completed'|'retry'|'failed' */
    public function runJob(array $job): string
    {
        $class = self::HANDLERS[$job['type']] ?? null;
        if ($class === null) {
            $this->fail($job, 'No handler registered for job type ' . $job['type'], false);
            return 'failed';
        }
        try {
            /** @var JobHandler $handler */
            $handler = new $class($this->app);
            $handler->handle($job);
            $this->complete($job);
            return 'completed';
        } catch (RetryableException $e) {
            $this->fail($job, $e->getMessage(), true, $e->retryAfterSeconds);
            return (int)$job['attempts'] < (int)$job['max_attempts'] ? 'retry' : 'failed';
        } catch (PermanentException $e) {
            $this->fail($job, $e->getMessage(), false);
            return 'failed';
        } catch (\Throwable $e) {
            \BharatSeo\Core\ErrorHandler::report($e);
            $this->fail($job, get_class($e) . ': ' . $e->getMessage(), true);
            return (int)$job['attempts'] < (int)$job['max_attempts'] ? 'retry' : 'failed';
        }
    }

    public function retryFailed(int $failedId, int $userId): bool
    {
        $f = $this->db->fetch('SELECT * FROM failed_jobs WHERE id = ? AND resolved_at IS NULL', [$failedId]);
        if (!$f) {
            return false;
        }
        $this->db->transaction(function (Database $db) use ($f, $userId): void {
            $db->run(
                'INSERT INTO job_queue (type, payload, project_id, status, priority, attempts, max_attempts, available_at) VALUES (?, ?, ?, \'pending\', 5, 0, ?, UTC_TIMESTAMP())',
                [$f['type'], $f['payload'], $f['project_id'], max(1, (int)$this->app->settings()->get('queue.default_max_attempts', 5))]
            );
            $db->run("UPDATE failed_jobs SET resolved_at = UTC_TIMESTAMP(), resolved_by = ?, resolution = 'retried' WHERE id = ?", [$userId, $f['id']]);
        });
        return true;
    }

    public function dismissFailed(int $failedId, int $userId): bool
    {
        return $this->db->run("UPDATE failed_jobs SET resolved_at = UTC_TIMESTAMP(), resolved_by = ?, resolution = 'dismissed' WHERE id = ? AND resolved_at IS NULL", [$userId, $failedId])->rowCount() > 0;
    }

    public function cancelPending(?int $projectId = null): int
    {
        $sql = "UPDATE job_queue SET status = 'cancelled', unique_key = NULL WHERE status = 'pending'";
        $params = [];
        if ($projectId !== null) {
            $sql .= ' AND project_id = ?';
            $params[] = $projectId;
        }
        return $this->db->run($sql, $params)->rowCount();
    }

    public function stats(): array
    {
        $rows = $this->db->fetchPairs('SELECT status, COUNT(*) FROM job_queue GROUP BY status');
        return [
            'pending' => (int)($rows['pending'] ?? 0),
            'running' => (int)($rows['running'] ?? 0),
            'completed' => (int)($rows['completed'] ?? 0),
            'failed' => (int)($rows['failed'] ?? 0),
            'cancelled' => (int)($rows['cancelled'] ?? 0),
            'unresolved_failed' => (int)$this->db->fetchColumn('SELECT COUNT(*) FROM failed_jobs WHERE resolved_at IS NULL'),
            'oldest_pending' => $this->db->fetchColumn("SELECT MIN(available_at) FROM job_queue WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP()"),
        ];
    }

    public static function memoryLimitBytes(): int
    {
        $v = trim((string)ini_get('memory_limit'));
        if ($v === '-1' || $v === '') {
            return PHP_INT_MAX;
        }
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
