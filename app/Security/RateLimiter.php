<?php
declare(strict_types=1);

namespace BharatSeo\Security;

use BharatSeo\Core\App;
use BharatSeo\Core\Database;

/** MySQL-backed fixed-window rate limiter and per-key spacing (e.g. per-domain crawl delay). */
final class RateLimiter
{
    private Database $db;

    public function __construct(App $app)
    {
        $this->db = $app->db();
    }

    /** Register a hit; returns true when still within $max hits per $windowSeconds. */
    public function hit(string $key, int $max, int $windowSeconds): bool
    {
        $key = substr($key, 0, 190);
        $this->db->run(
            'INSERT INTO rate_limits (bucket_key, hits, window_start, last_hit_at) VALUES (?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start < UTC_TIMESTAMP() - INTERVAL ? SECOND, 1, hits + 1),
               window_start = IF(window_start < UTC_TIMESTAMP() - INTERVAL ? SECOND, UTC_TIMESTAMP(), window_start),
               last_hit_at = UTC_TIMESTAMP(3)',
            [$key, $windowSeconds, $windowSeconds]
        );
        $hits = (int)$this->db->fetchColumn('SELECT hits FROM rate_limits WHERE bucket_key = ?', [$key]);
        return $hits <= $max;
    }

    public function tooMany(string $key, int $max, int $windowSeconds): bool
    {
        $row = $this->db->fetch('SELECT hits, window_start FROM rate_limits WHERE bucket_key = ? AND window_start >= UTC_TIMESTAMP() - INTERVAL ? SECOND', [substr($key, 0, 190), $windowSeconds]);
        return $row !== null && (int)$row['hits'] >= $max;
    }

    public function clear(string $key): void
    {
        $this->db->run('DELETE FROM rate_limits WHERE bucket_key = ?', [substr($key, 0, 190)]);
    }

    /**
     * Reserve the next request slot for $key with at least $intervalMs between slots.
     * Returns 0 when the slot was acquired now, otherwise the milliseconds to wait.
     */
    public function acquireSlot(string $key, int $intervalMs): int
    {
        $key = substr($key, 0, 190);
        return $this->db->transaction(function (Database $db) use ($key, $intervalMs): int {
            $db->run('INSERT IGNORE INTO rate_limits (bucket_key, hits, window_start, last_hit_at) VALUES (?, 0, UTC_TIMESTAMP(), \'1970-01-02 00:00:00.000\')', [$key]);
            $row = $db->fetch('SELECT TIMESTAMPDIFF(MICROSECOND, last_hit_at, UTC_TIMESTAMP(3)) DIV 1000 AS elapsed_ms FROM rate_limits WHERE bucket_key = ? FOR UPDATE', [$key]);
            $elapsed = (int)($row['elapsed_ms'] ?? PHP_INT_MAX);
            if ($elapsed < $intervalMs) {
                return $intervalMs - $elapsed;
            }
            $db->run('UPDATE rate_limits SET hits = hits + 1, last_hit_at = UTC_TIMESTAMP(3) WHERE bucket_key = ?', [$key]);
            return 0;
        });
    }
}
