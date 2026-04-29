<?php

declare(strict_types=1);

namespace App\Service;

use Mesahub\DatabaseHandle;

class LoginRateLimiter
{
    private const WINDOW_SECONDS = 900;
    private const MAX_ATTEMPTS   = 6;
    private const LOCK_SECONDS   = 900;

    public function __construct(private readonly DatabaseHandle $db)
    {
        $this->init();
    }

    private function init(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS login_rate_limits (
            ip TEXT PRIMARY KEY,
            failed_count INTEGER NOT NULL DEFAULT 0,
            first_failed_at INTEGER,
            last_failed_at INTEGER,
            lock_until INTEGER NOT NULL DEFAULT 0
        )");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_login_rate_lock ON login_rate_limits(lock_until)");
    }

    /**
     * @return array{allowed: bool, retry_after: int}
     */
    public function isAllowed(string $ip): array
    {
        $now    = time();
        $result = $this->db->query(
            "SELECT failed_count, first_failed_at, lock_until FROM login_rate_limits WHERE ip = ?",
            [$ip]
        );
        $entry = $result->rows[0] ?? null;

        if (!$entry) {
            return ['allowed' => true, 'retry_after' => 0];
        }

        $lockUntil = (int)($entry['lock_until'] ?? 0);
        if ($lockUntil > $now) {
            return ['allowed' => false, 'retry_after' => $lockUntil - $now];
        }

        $firstFailedAt = (int)($entry['first_failed_at'] ?? 0);
        if ($firstFailedAt > 0 && ($now - $firstFailedAt) > self::WINDOW_SECONDS) {
            $this->db->exec(
                "UPDATE login_rate_limits SET failed_count = 0, first_failed_at = NULL, last_failed_at = NULL, lock_until = 0 WHERE ip = ?",
                [$ip]
            );
            return ['allowed' => true, 'retry_after' => 0];
        }

        $failedCount = (int)($entry['failed_count'] ?? 0);
        if ($failedCount >= self::MAX_ATTEMPTS) {
            $newLock = $now + self::LOCK_SECONDS;
            $this->db->exec(
                "UPDATE login_rate_limits SET lock_until = ? WHERE ip = ?",
                [$newLock, $ip]
            );
            return ['allowed' => false, 'retry_after' => self::LOCK_SECONDS];
        }

        return ['allowed' => true, 'retry_after' => 0];
    }

    public function recordFailure(string $ip, int $now): void
    {
        $result = $this->db->query(
            "SELECT failed_count, first_failed_at FROM login_rate_limits WHERE ip = ?",
            [$ip]
        );
        $entry = $result->rows[0] ?? null;

        $failedCount   = 1;
        $firstFailedAt = $now;
        if ($entry) {
            $existingFirst = (int)($entry['first_failed_at'] ?? 0);
            $existingCount = (int)($entry['failed_count']    ?? 0);
            if ($existingFirst > 0 && ($now - $existingFirst) <= self::WINDOW_SECONDS) {
                $failedCount   = $existingCount + 1;
                $firstFailedAt = $existingFirst;
            }
        }

        $lockUntil = ($failedCount >= self::MAX_ATTEMPTS) ? $now + self::LOCK_SECONDS : 0;

        $this->db->exec(
            "INSERT INTO login_rate_limits (ip, failed_count, first_failed_at, last_failed_at, lock_until)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(ip) DO UPDATE SET
                 failed_count    = excluded.failed_count,
                 first_failed_at = excluded.first_failed_at,
                 last_failed_at  = excluded.last_failed_at,
                 lock_until      = excluded.lock_until",
            [$ip, $failedCount, $firstFailedAt, $now, $lockUntil]
        );

        $cleanupBefore = $now - (self::WINDOW_SECONDS * 2);
        $this->db->exec(
            "DELETE FROM login_rate_limits
             WHERE lock_until < ? AND (last_failed_at IS NULL OR last_failed_at < ?)",
            [$now, $cleanupBefore]
        );
    }

    public function clearFailure(string $ip): void
    {
        $this->db->exec("DELETE FROM login_rate_limits WHERE ip = ?", [$ip]);
    }
}
