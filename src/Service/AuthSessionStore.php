<?php

declare(strict_types=1);

namespace App\Service;

use Mesahub\DatabaseHandle;

/**
 * Server-side session records. The cookie is not enough on its own:
 * logout deletes the row, so a copied cookie stops working immediately.
 */
class AuthSessionStore
{
    public function __construct(private readonly DatabaseHandle $db)
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS auth_sessions (
            sid TEXT PRIMARY KEY,
            expires_at INTEGER NOT NULL,
            step_up_until INTEGER NOT NULL DEFAULT 0
        )");
    }

    public function start(int $ttlSeconds): string
    {
        $this->purge();
        $sid = bin2hex(random_bytes(16));
        $this->db->exec(
            "INSERT INTO auth_sessions (sid, expires_at, step_up_until) VALUES (?, ?, 0)",
            [$sid, time() + $ttlSeconds]
        );
        return $sid;
    }

    public function valid(string $sid): bool
    {
        if ($sid === '') {
            return false;
        }

        $row = $this->db->query(
            "SELECT expires_at FROM auth_sessions WHERE sid = ? LIMIT 1",
            [$sid]
        )->rows[0] ?? null;

        if (!$row) {
            return false;
        }
        if ((int)($row['expires_at'] ?? 0) < time()) {
            $this->revoke($sid);
            return false;
        }
        return true;
    }

    public function revoke(string $sid): void
    {
        if ($sid === '') {
            return;
        }
        $this->db->exec("DELETE FROM auth_sessions WHERE sid = ?", [$sid]);
    }

    public function grantStepUp(string $sid, int $until): void
    {
        $this->db->exec(
            "UPDATE auth_sessions SET step_up_until = ? WHERE sid = ?",
            [$until, $sid]
        );
    }

    public function stepUpActive(string $sid): bool
    {
        if ($sid === '' || !$this->valid($sid)) {
            return false;
        }
        $row = $this->db->query(
            "SELECT step_up_until FROM auth_sessions WHERE sid = ? LIMIT 1",
            [$sid]
        )->rows[0] ?? null;
        return (int)($row['step_up_until'] ?? 0) >= time();
    }

    public function purge(): void
    {
        $this->db->exec("DELETE FROM auth_sessions WHERE expires_at < ?", [time()]);
    }
}
