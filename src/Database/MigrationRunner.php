<?php

namespace App\Database;

use SQLite3;

/**
 * File-based migration runner for the SQLite schema.
 *
 * How it works:
 *   - SQLite stores an integer in the DB header via `PRAGMA user_version`.
 *   - Each migration is a private method named `migration001`, `migration002`, …
 *   - `run()` reads the current version and calls every migration whose version
 *     number is greater than the stored value, in ascending order.
 *   - After each migration succeeds the pragma is bumped immediately, so a
 *     partial failure leaves the DB at a consistent intermediate version.
 *
 * Adding a new migration:
 *   1. Add its version number to the MIGRATIONS constant.
 *   2. Add a private `migrationNNN()` method with the SQL / PHP logic.
 *   That's it — no other file needs touching.
 *
 * Current schema version: 4
 */
class MigrationRunner
{
    /** Ordered list of all known migration version numbers. */
    private const MIGRATIONS = [1, 2, 3, 4];

    private SQLite3 $db;

    public function __construct(SQLite3 $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function run(): void
    {
        $current = (int) $this->db->querySingle("PRAGMA user_version");

        foreach (self::MIGRATIONS as $version) {
            if ($version <= $current) {
                continue;
            }

            $method = sprintf('migration%03d', $version);
            $this->$method();
            $this->db->exec("PRAGMA user_version = {$version}");
        }
    }

    // -------------------------------------------------------------------------
    // Migrations
    // -------------------------------------------------------------------------

    /**
     * Migration 001 — Baseline DDL.
     *
     * Creates all core tables and indexes in the form they existed before any
     * later migrations ran.  Every statement uses `IF NOT EXISTS` / column-
     * existence guards so this is safe to run against a DB that was already
     * partially initialised by an older version of the code.
     *
     * Also applies the one-time secret_value nullable fix (originally called
     * migrateSecretValueNullable) before setting version = 1.
     */
    private function migration001(): void
    {
        // ── secret_history ────────────────────────────────────────────────────
        // New installs get the table created here with the correct nullable
        // schema (secret_value TEXT), so makeSecretValueNullable() is a no-op.
        // Existing (legacy) installs already have the table — IF NOT EXISTS is
        // a no-op for them and makeSecretValueNullable() fixes the column type.
        $this->db->exec("CREATE TABLE IF NOT EXISTS secret_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            secret_name TEXT NOT NULL,
            service_id TEXT,
            secret_value TEXT,
            rotated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        if (!$this->tableHasColumn('secret_history', 'service_id')) {
            $this->db->exec("ALTER TABLE secret_history ADD COLUMN service_id TEXT");
        }
        if (!$this->tableHasColumn('secret_history', 'new_secret_value')) {
            $this->db->exec("ALTER TABLE secret_history ADD COLUMN new_secret_value TEXT");
        }
        if (!$this->tableHasColumn('secret_history', 'trigger_type')) {
            $this->db->exec("ALTER TABLE secret_history ADD COLUMN trigger_type TEXT NOT NULL DEFAULT 'manual'");
        }

        // Make secret_value nullable if it was created with NOT NULL constraint
        // (this happens on DBs initialised before migration 001 ran).
        $this->makeSecretValueNullable();

        // ── managed_secrets ───────────────────────────────────────────────────
        $this->db->exec("CREATE TABLE IF NOT EXISTS managed_secrets (
            secret_name TEXT NOT NULL,
            service_id TEXT,
            length INTEGER DEFAULT 32,
            encoding TEXT DEFAULT 'hex',
            sync_group TEXT,
            interval_days INTEGER DEFAULT 30,
            interval_unit TEXT NOT NULL DEFAULT 'day',
            last_rotated DATETIME,
            PRIMARY KEY (secret_name, service_id)
        )");

        if (!$this->tableHasColumn('managed_secrets', 'interval_unit')) {
            $this->db->exec("ALTER TABLE managed_secrets ADD COLUMN interval_unit TEXT NOT NULL DEFAULT 'day'");
        }
        if (!$this->tableHasColumn('managed_secrets', 'sync_group')) {
            $this->db->exec("ALTER TABLE managed_secrets ADD COLUMN sync_group TEXT");
        }

        // ── indexes ───────────────────────────────────────────────────────────
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_secret_history_lookup
            ON secret_history(secret_name, service_id, rotated_at DESC)");

        // ── service_metadata ──────────────────────────────────────────────────
        $this->db->exec("CREATE TABLE IF NOT EXISTS service_metadata (
            service_id TEXT PRIMARY KEY,
            service_name TEXT NOT NULL,
            group_name TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // ── sync_groups ───────────────────────────────────────────────────────
        $this->db->exec("CREATE TABLE IF NOT EXISTS sync_groups (
            name TEXT PRIMARY KEY,
            length INTEGER NOT NULL DEFAULT 32,
            encoding TEXT NOT NULL DEFAULT 'hex',
            interval_days INTEGER NOT NULL DEFAULT 0,
            interval_unit TEXT NOT NULL DEFAULT 'day',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    /**
     * Migration 002 — Populate sync_groups canonical table.
     *
     * Deduplicates existing sync group configs from managed_secrets rows into
     * the new sync_groups table.  Safe to run on a DB that already has rows
     * in sync_groups because of INSERT OR IGNORE.
     */
    private function migration002(): void
    {
        $this->db->exec("
            INSERT OR IGNORE INTO sync_groups (name, length, encoding, interval_days, interval_unit)
            SELECT sync_group,
                   MIN(COALESCE(length, 32)),
                   MIN(COALESCE(encoding, 'hex')),
                   MIN(COALESCE(interval_days, 0)),
                   MIN(COALESCE(interval_unit, 'day'))
            FROM managed_secrets
            WHERE sync_group IS NOT NULL AND TRIM(sync_group) != ''
            GROUP BY sync_group
        ");
    }

    /**
     * Migration 003 — Add created_at to managed_secrets.
     *
     * Lets the scheduler distinguish a newly-added secret (should wait for
     * the next clock-aligned bucket) from one that was only manually rotated
     * (should fire on the next due bucket).
     */
    private function migration003(): void
    {
        if (!$this->tableHasColumn('managed_secrets', 'created_at')) {
            $this->db->exec("ALTER TABLE managed_secrets ADD COLUMN created_at DATETIME");
        }
        // Back-fill existing rows to now so they wait for the next bucket.
        $this->db->exec("UPDATE managed_secrets SET created_at = CURRENT_TIMESTAMP WHERE created_at IS NULL");
    }

    /**
     * Migration 004 — Add next_rotation_at to managed_secrets.
     *
     * Stores the pre-computed next auto-rotation timestamp as a single source
     * of truth for cron, dashboard, and API — no bucket arithmetic scattered
     * across files.
     */
    private function migration004(): void
    {
        if (!$this->tableHasColumn('managed_secrets', 'next_rotation_at')) {
            $this->db->exec("ALTER TABLE managed_secrets ADD COLUMN next_rotation_at DATETIME");
        }

        // Back-fill existing rows: compute the next clock-aligned bucket so
        // they do NOT fire immediately on their first cron run.
        $result = $this->db->query(
            "SELECT secret_name, service_id, interval_days, interval_unit
             FROM managed_secrets
             WHERE next_rotation_at IS NULL AND interval_days > 0"
        );
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        $result->finalize(); // must close read cursor before issuing writes

        $divisorMap = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
        $now        = time();

        foreach ($rows as $row) {
            $divisor         = $divisorMap[(string)($row['interval_unit'] ?? 'day')] ?? 86400;
            $intervalSeconds = (int)$row['interval_days'] * $divisor;
            if ($intervalSeconds <= 0) {
                continue;
            }
            $bucketStart = (int)(floor($now / $intervalSeconds) * $intervalSeconds);
            $nextAt      = gmdate('Y-m-d H:i:s', $bucketStart + $intervalSeconds);
            $sid         = $row['service_id'] ?? null;

            $stmt = $this->db->prepare(
                "UPDATE managed_secrets SET next_rotation_at = :next
                 WHERE secret_name = :name
                   AND (service_id = :sid OR (service_id IS NULL AND :sid IS NULL))"
            );
            $stmt->bindValue(':next', $nextAt, SQLITE3_TEXT);
            $stmt->bindValue(':name', (string)$row['secret_name'], SQLITE3_TEXT);
            $stmt->bindValue(':sid', $sid, $sid === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Rebuild secret_history so that secret_value becomes nullable.
     * Only needed when the column was originally created with NOT NULL.
     */
    private function makeSecretValueNullable(): void
    {
        $result       = $this->db->query("PRAGMA table_info('secret_history')");
        $needsRebuild = false;
        while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($col['name'] === 'secret_value' && (int)$col['notnull'] === 1) {
                $needsRebuild = true;
                break;
            }
        }
        // MUST finalize before any write — un-finalized cursors on sqlite_master
        // cause SQLITE_LOCKED on schema writes even within the same connection.
        $result->finalize();

        if (!$needsRebuild) {
            return;
        }

        // Clean up any stale table left by a previous partially-failed run.
        $this->db->exec("DROP TABLE IF EXISTS secret_history_old");

        // All four DDL steps must be inside one transaction so the rename dance
        // is fully atomic (all succeed or all roll back).
        // IMPORTANT: the DROP of the old table must also be inside the same
        // transaction — dropping it after COMMIT causes SQLITE_LOCKED because
        // SQLite retains an internal page-cache reference to secret_history_old
        // from the INSERT...SELECT even after the transaction commits.
        //
        // Note: PRAGMA locking_mode = EXCLUSIVE is intentionally NOT used —
        // it was the original cause of SQLITE_LOCKED because it conflicts with
        // any still-live schema cursors on the same connection.
        // A plain BEGIN is sufficient; all cursors are finalized above.
        $this->db->exec("BEGIN");
        try {
            $this->execOrFail("ALTER TABLE secret_history RENAME TO secret_history_old");
            $this->execOrFail("CREATE TABLE secret_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                secret_name TEXT NOT NULL,
                service_id TEXT,
                secret_value TEXT,
                new_secret_value TEXT,
                trigger_type TEXT NOT NULL DEFAULT 'manual',
                rotated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $this->execOrFail("INSERT INTO secret_history
                SELECT id, secret_name, service_id, secret_value, new_secret_value, trigger_type, rotated_at
                FROM secret_history_old");
            $this->execOrFail("DROP TABLE secret_history_old");
            $this->db->exec("COMMIT");
        } catch (\RuntimeException $e) {
            $this->db->exec("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Execute a SQL statement or throw if it fails.
     * SQLite3::exec() returns false on error (no exception by default).
     */
    private function execOrFail(string $sql): void
    {
        if ($this->db->exec($sql) === false) {
            throw new \RuntimeException(
                'SQLite exec failed (' . $this->db->lastErrorCode() . '): ' . $this->db->lastErrorMsg()
            );
        }
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        $safe   = str_replace("'", "''", $table);
        $result = $this->db->query("PRAGMA table_info('{$safe}')");
        $found  = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (($row['name'] ?? null) === $column) {
                $found = true;
                break;
            }
        }
        // Always finalize — early-returning without finalize leaves an open read
        // cursor on sqlite_master that will cause SQLITE_LOCKED on later DDL.
        $result->finalize();
        return $found;
    }
}
