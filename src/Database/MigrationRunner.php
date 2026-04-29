<?php

namespace App\Database;

use Mesahub\DatabaseHandle;

/**
 * Migration runner for the mesahub database schema.
 *
 * How it works:
 *   - A `schema_migrations` table stores the versions that have already run.
 *   - Each migration is a private method named `migration001`, `migration002`, …
 *   - `run()` reads applied versions and calls every pending migration in order.
 *   - After each migration the version is recorded immediately.
 *
 * Adding a new migration:
 *   1. Add its version number to the MIGRATIONS constant.
 *   2. Add a private `migrationNNN()` method with the SQL / PHP logic.
 *
 * Current schema version: 4
 */
class MigrationRunner
{
    /** Ordered list of all known migration version numbers. */
    private const MIGRATIONS = [1, 2, 3, 4];

    public function __construct(private readonly DatabaseHandle $db) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function run(): void
    {
        // Bootstrap the migrations tracking table on first run.
        $this->db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version INTEGER PRIMARY KEY
        )");

        $applied = array_column(
            $this->db->query("SELECT version FROM schema_migrations")->rows,
            'version'
        );

        foreach (self::MIGRATIONS as $version) {
            if (in_array((string)$version, array_map('strval', $applied), true)) {
                continue;
            }

            $method = sprintf('migration%03d', $version);
            $this->$method();
            $this->db->exec("INSERT OR IGNORE INTO schema_migrations (version) VALUES (?)", [$version]);
        }
    }

    // -------------------------------------------------------------------------
    // Migrations
    // -------------------------------------------------------------------------

    /**
     * Migration 001 — Baseline DDL.
     */
    private function migration001(): void
    {
        // ── secret_history ────────────────────────────────────────────────────
        $this->db->exec("CREATE TABLE IF NOT EXISTS secret_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            secret_name TEXT NOT NULL,
            service_id TEXT,
            secret_value TEXT,
            new_secret_value TEXT,
            trigger_type TEXT NOT NULL DEFAULT 'manual',
            rotated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

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
     */
    private function migration003(): void
    {
        if (!$this->tableHasColumn('managed_secrets', 'created_at')) {
            $this->db->exec("ALTER TABLE managed_secrets ADD COLUMN created_at DATETIME");
        }
        $this->db->exec("UPDATE managed_secrets SET created_at = CURRENT_TIMESTAMP WHERE created_at IS NULL");
    }

    /**
     * Migration 004 — Add next_rotation_at to managed_secrets.
     */
    private function migration004(): void
    {
        if (!$this->tableHasColumn('managed_secrets', 'next_rotation_at')) {
            $this->db->exec("ALTER TABLE managed_secrets ADD COLUMN next_rotation_at DATETIME");
        }

        $result = $this->db->query(
            "SELECT secret_name, service_id, interval_days, interval_unit
             FROM managed_secrets
             WHERE next_rotation_at IS NULL AND interval_days > 0"
        );

        $divisorMap = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
        $now        = time();

        foreach ($result->rows as $row) {
            $divisor         = $divisorMap[(string)($row['interval_unit'] ?? 'day')] ?? 86400;
            $intervalSeconds = (int)$row['interval_days'] * $divisor;
            if ($intervalSeconds <= 0) {
                continue;
            }
            $bucketStart = (int)(floor($now / $intervalSeconds) * $intervalSeconds);
            $nextAt      = gmdate('Y-m-d H:i:s', $bucketStart + $intervalSeconds);
            $sid         = $row['service_id'] ?? null;

            $this->db->exec(
                "UPDATE managed_secrets SET next_rotation_at = ?
                 WHERE secret_name = ?
                   AND (service_id = ? OR (service_id IS NULL AND ? IS NULL))",
                [$nextAt, (string)$row['secret_name'], $sid, $sid]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function tableHasColumn(string $table, string $column): bool
    {
        $rows = $this->db->query("PRAGMA table_info('{$table}')")->rows;
        foreach ($rows as $col) {
            if (($col['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }
}
