<?php

declare(strict_types=1);

namespace App\Service;

/**
 * SQLite-backed login rate limiter.
 * Extracted from the monolithic index.php; behaviour is identical.
 */
class LoginRateLimiter
{
    private const WINDOW_SECONDS = 900;
    private const MAX_ATTEMPTS   = 6;
    private const LOCK_SECONDS   = 900;

    private ?\SQLite3 $db = null;

    private function dbPath(): string
    {
        $path = dirname(__DIR__, 2) . '/storage/db/login_rate_limit.sqlite';
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $path;
    }

    private function db(): \SQLite3
    {
        if ($this->db instanceof \SQLite3) {
            return $this->db;
        }
        $this->db = new \SQLite3($this->dbPath());
        $this->db->busyTimeout(5000);
        $this->db->exec("CREATE TABLE IF NOT EXISTS login_rate_limits (
            ip TEXT PRIMARY KEY,
            failed_count INTEGER NOT NULL DEFAULT 0,
            first_failed_at INTEGER,
            last_failed_at INTEGER,
            lock_until INTEGER NOT NULL DEFAULT 0
        )");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_login_rate_lock ON login_rate_limits(lock_until)");
        return $this->db;
    }

    /**
     * @return array{allowed: bool, retry_after: int}
     */
    public function isAllowed(string $ip): array
    {
        $db  = $this->db();
        $now = time();

        $stmt = $db->prepare("SELECT failed_count, first_failed_at, lock_until FROM login_rate_limits WHERE ip = :ip");
        $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $entry = $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;

        if (!$entry) {
            return ['allowed' => true, 'retry_after' => 0];
        }

        $lockUntil = (int)($entry['lock_until'] ?? 0);
        if ($lockUntil > $now) {
            return ['allowed' => false, 'retry_after' => $lockUntil - $now];
        }

        $firstFailedAt = (int)($entry['first_failed_at'] ?? 0);
        if ($firstFailedAt > 0 && ($now - $firstFailedAt) > self::WINDOW_SECONDS) {
            $reset = $db->prepare("UPDATE login_rate_limits
                SET failed_count = 0, first_failed_at = NULL, last_failed_at = NULL, lock_until = 0
                WHERE ip = :ip");
            $reset->bindValue(':ip', $ip, SQLITE3_TEXT);
            $reset->execute();
            return ['allowed' => true, 'retry_after' => 0];
        }

        $failedCount = (int)($entry['failed_count'] ?? 0);
        if ($failedCount >= self::MAX_ATTEMPTS) {
            $newLock = $now + self::LOCK_SECONDS;
            $lockStmt = $db->prepare("UPDATE login_rate_limits SET lock_until = :lock_until WHERE ip = :ip");
            $lockStmt->bindValue(':lock_until', $newLock, SQLITE3_INTEGER);
            $lockStmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $lockStmt->execute();
            return ['allowed' => false, 'retry_after' => self::LOCK_SECONDS];
        }

        return ['allowed' => true, 'retry_after' => 0];
    }

    public function recordFailure(string $ip, int $now): void
    {
        $db = $this->db();

        $stmt  = $db->prepare("SELECT failed_count, first_failed_at FROM login_rate_limits WHERE ip = :ip");
        $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $entry = $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;

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

        $upsert = $db->prepare("INSERT INTO login_rate_limits
            (ip, failed_count, first_failed_at, last_failed_at, lock_until)
            VALUES (:ip, :failed_count, :first_failed_at, :last_failed_at, :lock_until)
            ON CONFLICT(ip) DO UPDATE SET
                failed_count    = excluded.failed_count,
                first_failed_at = excluded.first_failed_at,
                last_failed_at  = excluded.last_failed_at,
                lock_until      = excluded.lock_until");
        $upsert->bindValue(':ip',             $ip,             SQLITE3_TEXT);
        $upsert->bindValue(':failed_count',   $failedCount,    SQLITE3_INTEGER);
        $upsert->bindValue(':first_failed_at', $firstFailedAt, SQLITE3_INTEGER);
        $upsert->bindValue(':last_failed_at',  $now,           SQLITE3_INTEGER);
        $upsert->bindValue(':lock_until',      $lockUntil,     SQLITE3_INTEGER);
        $upsert->execute();

        $cleanupBefore = $now - (self::WINDOW_SECONDS * 2);
        $cleanup = $db->prepare("DELETE FROM login_rate_limits
            WHERE lock_until < :now AND (last_failed_at IS NULL OR last_failed_at < :cleanup_before)");
        $cleanup->bindValue(':now',            $now,           SQLITE3_INTEGER);
        $cleanup->bindValue(':cleanup_before', $cleanupBefore, SQLITE3_INTEGER);
        $cleanup->execute();
    }

    public function clearFailure(string $ip): void
    {
        $stmt = $this->db()->prepare("DELETE FROM login_rate_limits WHERE ip = :ip");
        $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $stmt->execute();
    }
}
