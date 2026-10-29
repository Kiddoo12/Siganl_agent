<?php
declare(strict_types=1);

namespace App\Core;

/** Fixed-window rate limiter backed by MySQL. Fails open if the DB is unavailable. */
final class RateLimiter
{
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        try {
            $key = substr(hash('sha256', $key), 0, 64);
            $now = time();
            $row = Database::fetch('SELECT hits, window_start FROM rate_limits WHERE rl_key = ?', [$key]);
            if (!$row || $now - (int)$row['window_start'] >= $windowSeconds) {
                Database::query(
                    'REPLACE INTO rate_limits (rl_key, hits, window_start) VALUES (?, 1, ?)',
                    [$key, $now]
                );
                return true;
            }
            if ((int)$row['hits'] >= $max) {
                return false;
            }
            Database::query('UPDATE rate_limits SET hits = hits + 1 WHERE rl_key = ?', [$key]);
            return true;
        } catch (\Throwable $e) {
            Logger::warning('Rate limiter unavailable', ['err' => $e->getMessage()]);
            return true;
        }
    }

    public static function isLocked(string $key, int $max, int $windowSeconds): bool
    {
        try {
            $key = substr(hash('sha256', $key), 0, 64);
            $row = Database::fetch('SELECT hits, window_start FROM rate_limits WHERE rl_key = ?', [$key]);
            if (!$row) {
                return false;
            }
            $now = time();
            if ($now - (int)$row['window_start'] >= $windowSeconds) {
                return false;
            }
            return (int)$row['hits'] >= $max;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function clear(string $key): void
    {
        try {
            $key = substr(hash('sha256', $key), 0, 64);
            Database::query('DELETE FROM rate_limits WHERE rl_key = ?', [$key]);
        } catch (\Throwable $e) {
            // fail-safe ignore
        }
    }
}
