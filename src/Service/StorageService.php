<?php

namespace App\Service;

use SQLite3;

class StorageService
{
    private SQLite3 $db;
    private string $masterKey;

    public function __construct(string $dbPath, string $masterKey)
    {
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir) && !mkdir($dbDir, 0775, true) && !is_dir($dbDir)) {
            throw new \RuntimeException("Unable to create database directory: {$dbDir}");
        }

        $this->db = new SQLite3($dbPath);
        $this->db->busyTimeout(5000);
        $this->masterKey = $masterKey;
        $this->init();
    }

    private function init(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS secret_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            secret_name TEXT NOT NULL,
            service_id TEXT,
            secret_value TEXT NOT NULL,
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

        // Migrate secret_value to allow NULL (for first-ever rotations with no prior value)
        $this->migrateSecretValueNullable();

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

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_secret_history_lookup ON secret_history(secret_name, service_id, rotated_at DESC)");

        $this->db->exec("CREATE TABLE IF NOT EXISTS service_metadata (
            service_id TEXT PRIMARY KEY,
            service_name TEXT NOT NULL,
            group_name TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS sync_groups (
            name TEXT PRIMARY KEY,
            length INTEGER NOT NULL DEFAULT 32,
            encoding TEXT NOT NULL DEFAULT 'hex',
            interval_days INTEGER NOT NULL DEFAULT 0,
            interval_unit TEXT NOT NULL DEFAULT 'day',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->migrateToSyncGroupsTable();
        $this->migrateAddCreatedAt();
        $this->migrateAddNextRotationAt();
    }

    /**
     * Migrate secret_value to be nullable using SQLite user_version as a
     * one-time migration flag. user_version is an integer stored in the DB
     * header — safe against concurrent requests since SQLite serialises writes.
     * Current version guard: 1 = secret_value nullable migration applied.
     */
    private function migrateSecretValueNullable(): void
    {
        $row = $this->db->querySingle("PRAGMA user_version");
        if ((int)$row >= 1) {
            return; // already migrated
        }

        // Check if migration is actually needed (column may already be nullable
        // on a fresh install because CREATE TABLE above omits NOT NULL)
        $result = $this->db->query("PRAGMA table_info('secret_history')");
        $needsMigration = false;
        while ($col = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($col['name'] === 'secret_value' && (int)$col['notnull'] === 1) {
                $needsMigration = true;
                break;
            }
        }

        if ($needsMigration) {
            $this->db->exec("BEGIN");
            $this->db->exec("ALTER TABLE secret_history RENAME TO secret_history_old");
            $this->db->exec("CREATE TABLE secret_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                secret_name TEXT NOT NULL,
                service_id TEXT,
                secret_value TEXT,
                new_secret_value TEXT,
                trigger_type TEXT NOT NULL DEFAULT 'manual',
                rotated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $this->db->exec("INSERT INTO secret_history SELECT id, secret_name, service_id, secret_value, new_secret_value, trigger_type, rotated_at FROM secret_history_old");
            $this->db->exec("DROP TABLE secret_history_old");
            $this->db->exec("COMMIT");
        }

        $this->db->exec("PRAGMA user_version = 1");
    }

    /**
     * Migration v2: populate the canonical sync_groups table from any pre-existing
     * data in managed_secrets.  Uses user_version = 2 as the one-time flag.
     */
    private function migrateToSyncGroupsTable(): void
    {
        $version = (int)$this->db->querySingle("PRAGMA user_version");
        if ($version >= 2) {
            return;
        }

        // Deduplicate existing group configs into sync_groups
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

        $this->db->exec("PRAGMA user_version = 2");
    }

    /**
     * Migration v3: add created_at to managed_secrets so the scheduler can
     * distinguish 'just added this window' from 'only manually rotated'.
     */
    private function migrateAddCreatedAt(): void
    {
        $version = (int)$this->db->querySingle("PRAGMA user_version");
        if ($version >= 3) {
            return;
        }

        if (!$this->tableHasColumn('managed_secrets', 'created_at')) {
            $this->db->exec("ALTER TABLE managed_secrets ADD COLUMN created_at DATETIME");
            // Existing rows: set to now — they will wait for the next bucket, then schedule normally.
            $this->db->exec("UPDATE managed_secrets SET created_at = CURRENT_TIMESTAMP WHERE created_at IS NULL");
        }

        $this->db->exec("PRAGMA user_version = 3");
    }

    /**
     * Migration v4: add next_rotation_at to managed_secrets.
     * Stores the pre-computed timestamp of the next scheduled auto-rotation so
     * the cron, dashboard, and API all share one source of truth — no bucket
     * arithmetic scattered across files.
     */
    private function migrateAddNextRotationAt(): void
    {
        $version = (int)$this->db->querySingle("PRAGMA user_version");
        if ($version >= 4) {
            return;
        }

        if (!$this->tableHasColumn('managed_secrets', 'next_rotation_at')) {
            $this->db->exec("ALTER TABLE managed_secrets ADD COLUMN next_rotation_at DATETIME");
        }

        // Back-fill existing rows: compute the next clock-aligned bucket so they
        // do NOT fire immediately — they will wait for the next natural window.
        $result     = $this->db->query(
            "SELECT secret_name, service_id, interval_days, interval_unit
             FROM managed_secrets
             WHERE next_rotation_at IS NULL AND interval_days > 0"
        );
        $divisorMap = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
        $now        = time();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $divisor         = $divisorMap[(string)($row['interval_unit'] ?? 'day')] ?? 86400;
            $intervalSeconds = (int)$row['interval_days'] * $divisor;
            if ($intervalSeconds <= 0) {
                continue;
            }
            $bucketStart = (int)(floor($now / $intervalSeconds) * $intervalSeconds);
            $nextAt      = gmdate('Y-m-d H:i:s', $bucketStart + $intervalSeconds);
            $sid         = $row['service_id'] ?? null;
            $upd         = $this->db->prepare(
                "UPDATE managed_secrets SET next_rotation_at = :next
                 WHERE secret_name = :name
                   AND (service_id = :sid OR (service_id IS NULL AND :sid IS NULL))"
            );
            $upd->bindValue(':next', $nextAt, SQLITE3_TEXT);
            $upd->bindValue(':name', (string)$row['secret_name'], SQLITE3_TEXT);
            $upd->bindValue(':sid', $sid, $sid === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $upd->execute();
        }

        $this->db->exec("PRAGMA user_version = 4");
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        $escapedTable = str_replace("'", "''", $table);
        $result = $this->db->query("PRAGMA table_info('{$escapedTable}')");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    public function saveConfig(string $name, ?string $serviceId, array $config): bool
    {
        $syncGroup = isset($config['sync_group']) ? trim((string)$config['sync_group']) : '';

        if ($syncGroup !== '') {
            // Look up the canonical policy for this group
            $groupPolicy = $this->getSyncGroupConfig($syncGroup);
            if ($groupPolicy === null) {
                // Brand-new group — publish it with the submitted policy
                $this->saveSyncGroupConfig($syncGroup, $config);
                $groupPolicy = $this->getSyncGroupConfig($syncGroup);
            }
            // Member row always mirrors the group policy (eliminates drift)
            $config = array_merge($config, [
                'length'        => $groupPolicy['length'],
                'encoding'      => $groupPolicy['encoding'],
                'interval_days' => $groupPolicy['interval_days'],
                'interval_unit' => $groupPolicy['interval_unit'],
            ]);
        }

        // Compute the next clock-aligned rotation bucket. Passed to INSERT for new rows;
        // on UPDATE only applied if the interval actually changed (see CASE in SQL below).
        $intervalDays = (int)($config['interval_days'] ?? 0);
        $intervalUnit = $config['interval_unit'] ?? 'day';
        $divisorMap   = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
        $intervalSecs = $intervalDays * ($divisorMap[$intervalUnit] ?? 86400);
        if ($intervalSecs > 0) {
            $now         = time();
            $bucketStart = (int)(floor($now / $intervalSecs) * $intervalSecs);
            $nextAt      = gmdate('Y-m-d H:i:s', $bucketStart + $intervalSecs);
        } else {
            $nextAt = null; // manual-only
        }

        $stmt = $this->db->prepare("
            INSERT INTO managed_secrets
                (secret_name, service_id, length, encoding, sync_group, interval_days, interval_unit, created_at, next_rotation_at)
            VALUES (:name, :sid, :len, :enc, :sync_group, :int, :unit, CURRENT_TIMESTAMP, :next_at)
            ON CONFLICT(secret_name, service_id) DO UPDATE SET
                length           = excluded.length,
                encoding         = excluded.encoding,
                sync_group       = excluded.sync_group,
                interval_days    = excluded.interval_days,
                interval_unit    = excluded.interval_unit,
                next_rotation_at = CASE
                    WHEN excluded.interval_days != managed_secrets.interval_days
                      OR excluded.interval_unit  != managed_secrets.interval_unit
                    THEN excluded.next_rotation_at
                    ELSE managed_secrets.next_rotation_at
                END
        ");
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':sid', $serviceId, $serviceId === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':len', $config['length'] ?? 32, SQLITE3_INTEGER);
        $stmt->bindValue(':enc', $config['encoding'] ?? 'hex', SQLITE3_TEXT);
        $stmt->bindValue(':sync_group', $syncGroup !== '' ? $syncGroup : null, $syncGroup !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':int', $config['interval_days'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':unit', $config['interval_unit'] ?? 'day', SQLITE3_TEXT);
        $stmt->bindValue(':next_at', $nextAt, $nextAt === null ? SQLITE3_NULL : SQLITE3_TEXT);

        return (bool)$stmt->execute();
    }

    /**
     * Fetch the canonical policy for a single sync group from the sync_groups table.
     * Returns null if the group does not exist.
     */
    public function getSyncGroupConfig(string $name): ?array
    {
        $stmt = $this->db->prepare("SELECT name, length, encoding, interval_days, interval_unit FROM sync_groups WHERE name = :name LIMIT 1");
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'length'        => (int)($row['length'] ?? 32),
            'encoding'      => (string)($row['encoding'] ?? 'hex'),
            'interval_days' => (int)($row['interval_days'] ?? 0),
            'interval_unit' => (string)($row['interval_unit'] ?? 'day'),
        ];
    }

    /**
     * Upsert the policy for a sync group and bulk-update all member rows so they
     * always mirror the canonical policy (no drift possible).
     */
    public function saveSyncGroupConfig(string $name, array $policy): bool
    {
        $length       = max(8, min(256, (int)($policy['length'] ?? 32)));
        $encoding     = in_array($policy['encoding'] ?? 'hex', ['hex', 'base64', 'alphanumeric'], true) ? $policy['encoding'] : 'hex';
        $intervalDays = max(0, (int)($policy['interval_days'] ?? 0));
        $intervalUnit = in_array($policy['interval_unit'] ?? 'day', ['minute', 'hour', 'day'], true) ? $policy['interval_unit'] : 'day';

        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO sync_groups (name, length, encoding, interval_days, interval_unit)
            VALUES (:name, :len, :enc, :int, :unit)
        ");
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':len', $length, SQLITE3_INTEGER);
        $stmt->bindValue(':enc', $encoding, SQLITE3_TEXT);
        $stmt->bindValue(':int', $intervalDays, SQLITE3_INTEGER);
        $stmt->bindValue(':unit', $intervalUnit, SQLITE3_TEXT);
        if (!$stmt->execute()) {
            return false;
        }

        // Propagate the new policy to every member of the group
        $upd = $this->db->prepare("
            UPDATE managed_secrets
            SET length = :len, encoding = :enc, interval_days = :int, interval_unit = :unit
            WHERE sync_group = :group
        ");
        $upd->bindValue(':len', $length, SQLITE3_INTEGER);
        $upd->bindValue(':enc', $encoding, SQLITE3_TEXT);
        $upd->bindValue(':int', $intervalDays, SQLITE3_INTEGER);
        $upd->bindValue(':unit', $intervalUnit, SQLITE3_TEXT);
        $upd->bindValue(':group', $name, SQLITE3_TEXT);
        $upd->execute();

        return true;
    }

    /**
     * Delete a sync group entry and unlink all its member secrets.
     * Member rows are kept but their sync_group column is set to NULL so they
     * become independently-managed secrets.
     */
    public function deleteSyncGroup(string $name): bool
    {
        $this->db->exec('BEGIN');

        $unlink = $this->db->prepare("UPDATE managed_secrets SET sync_group = NULL WHERE sync_group = :name");
        $unlink->bindValue(':name', $name, SQLITE3_TEXT);
        $unlink->execute();

        $del = $this->db->prepare("DELETE FROM sync_groups WHERE name = :name");
        $del->bindValue(':name', $name, SQLITE3_TEXT);
        $del->execute();

        $this->db->exec('COMMIT');
        return true;
    }

    public function getSyncGroupMembers(string $groupName): array
    {
        $groupName = trim($groupName);
        if ($groupName === '') {
            return [];
        }

        $stmt = $this->db->prepare("SELECT * FROM managed_secrets WHERE sync_group = :group_name ORDER BY service_id, secret_name");
        $stmt->bindValue(':group_name', $groupName, SQLITE3_TEXT);
        $result = $stmt->execute();

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function getDistinctSyncGroups(): array
    {
        // Read from the canonical sync_groups table
        $result = $this->db->query("SELECT name FROM sync_groups ORDER BY name ASC");
        $groups = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $groups[] = (string)($row['name'] ?? '');
        }
        return $groups;
    }

    public function getSyncGroupConfigMap(): array
    {
        // Read directly from the canonical sync_groups table (single source of truth)
        $result = $this->db->query("SELECT name, length, encoding, interval_days, interval_unit FROM sync_groups ORDER BY name ASC");
        $map = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $group = trim((string)($row['name'] ?? ''));
            if ($group === '') {
                continue;
            }
            $map[$group] = [
                'length'        => (int)($row['length'] ?? 32),
                'encoding'      => (string)($row['encoding'] ?? 'hex'),
                'interval_days' => (int)($row['interval_days'] ?? 0),
                'interval_unit' => (string)($row['interval_unit'] ?? 'day'),
            ];
        }
        return $map;
    }

    public function getServiceNameMapFromMetadata(): array
    {
        $result = $this->db->query("SELECT service_id, service_name FROM service_metadata");
        $map = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $id = (string)($row['service_id'] ?? '');
            $name = (string)($row['service_name'] ?? '');
            if ($id !== '' && $name !== '') {
                $map[$id] = $name;
            }
        }
        return $map;
    }

    public function getManagedSecrets(): array
    {
        $result = $this->db->query("SELECT * FROM managed_secrets");
        $managed = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $key = ($row['service_id'] ?: 'global') . ':' . $row['secret_name'];
            $managed[$key] = $row;
        }
        return $managed;
    }

    public function deleteConfig(string $name, ?string $serviceId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM managed_secrets WHERE secret_name = :name AND (service_id = :sid OR (:sid IS NULL AND service_id IS NULL))");
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':sid', $serviceId, $serviceId === null ? SQLITE3_NULL : SQLITE3_TEXT);
        return (bool)$stmt->execute();
    }

    /**
     * Back-fill the new_secret_value of the most recent history row for a secret.
     * Called BEFORE inserting a new rotation so that the previous row's "new value"
     * equals the current value we're about to rotate away from.
     */
    public function updateLatestHistoryNewValue(string $name, ?string $serviceId, string $currentPlainValue): void
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM secret_history
             WHERE secret_name = :name
               AND (service_id = :sid OR (service_id IS NULL AND :sid IS NULL))
             ORDER BY rotated_at DESC, id DESC
             LIMIT 1"
        );
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':sid', $serviceId, $serviceId === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            return;
        }

        $encrypted = CryptoService::encrypt($currentPlainValue, $this->masterKey);
        $upd = $this->db->prepare("UPDATE secret_history SET new_secret_value = :val WHERE id = :id");
        $upd->bindValue(':val', $encrypted, SQLITE3_TEXT);
        $upd->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
        $upd->execute();
    }

    public function addHistory(string $name, ?string $oldPlainValue, ?string $serviceId = null, string $triggerType = 'manual'): bool
    {
        $encryptedOldValue = $oldPlainValue !== null
            ? CryptoService::encrypt($oldPlainValue, $this->masterKey)
            : null;

        $stmt = $this->db->prepare("INSERT INTO secret_history (secret_name, service_id, secret_value, new_secret_value, trigger_type) VALUES (:name, :sid, :old_val, NULL, :trigger)");
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':sid', $serviceId, $serviceId === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':old_val', $encryptedOldValue, $encryptedOldValue === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':trigger', $triggerType, SQLITE3_TEXT);
        $result = $stmt->execute();

        // Enforce retention policy (keep last 3 per secret + scope)
        $cleanup = $this->db->prepare("DELETE FROM secret_history WHERE id NOT IN (
            SELECT id FROM secret_history
            WHERE secret_name = :name
              AND (service_id = :sid OR (service_id IS NULL AND :sid IS NULL))
            ORDER BY rotated_at DESC, id DESC LIMIT 3
        ) AND secret_name = :name
          AND (service_id = :sid OR (service_id IS NULL AND :sid IS NULL))
        ");
        $cleanup->bindValue(':name', $name, SQLITE3_TEXT);
        $cleanup->bindValue(':sid', $serviceId, $serviceId === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $cleanup->execute();

        return (bool)$result;
    }

    /**
     * Returns the unix timestamp of the most recent automatic rotation for a secret,
     * or null if it has never been auto-rotated.
     */
    public function getLastAutoRotatedAt(string $name, ?string $serviceId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT rotated_at FROM secret_history
             WHERE secret_name = :name
               AND (service_id = :sid OR (service_id IS NULL AND :sid IS NULL))
               AND trigger_type IN ('auto', 'sync-auto')
             ORDER BY rotated_at DESC, id DESC
             LIMIT 1"
        );
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':sid', $serviceId, $serviceId === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if (!$row || empty($row['rotated_at'])) {
            return null;
        }
        $ts = strtotime((string)$row['rotated_at']);
        return $ts !== false ? $ts : null;
    }

    public function getHistory(string $name, ?string $serviceId = null): array
    {
        $stmt = $this->db->prepare("SELECT * FROM secret_history
            WHERE secret_name = :name
              AND (service_id = :sid OR (service_id IS NULL AND :sid IS NULL))
            ORDER BY rotated_at DESC");
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':sid', $serviceId, $serviceId === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $result = $stmt->execute();

        $history = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!empty($row['secret_value'])) {
                $row['secret_value'] = CryptoService::decrypt($row['secret_value'], $this->masterKey);
            } else {
                $row['secret_value'] = null;
            }
            $history[] = $row;
        }
        return $history;
    }

    public function getRecentHistory(?string $serviceId = null, int $limit = 30): array
    {
        $limit = max(1, min($limit, 200));

        if ($serviceId === null) {
            $stmt = $this->db->prepare(
                "SELECT sh.id, sh.secret_name, sh.service_id, sh.trigger_type, sh.rotated_at,
                        sm.service_name
                 FROM secret_history sh
                 LEFT JOIN service_metadata sm ON sh.service_id = sm.service_id
                 ORDER BY datetime(sh.rotated_at) DESC, sh.id DESC
                 LIMIT :limit"
            );
            $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        } else {
            $stmt = $this->db->prepare(
                "SELECT sh.id, sh.secret_name, sh.service_id, sh.trigger_type, sh.rotated_at,
                        sm.service_name
                 FROM secret_history sh
                 LEFT JOIN service_metadata sm ON sh.service_id = sm.service_id
                 WHERE sh.service_id = :sid
                 ORDER BY datetime(sh.rotated_at) DESC, sh.id DESC
                 LIMIT :limit"
            );
            $stmt->bindValue(':sid', $serviceId, SQLITE3_TEXT);
            $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        }

        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function getHistoryDetailById(int $historyId): ?array
    {
        if ($historyId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT id, secret_name, service_id, secret_value, new_secret_value, trigger_type, rotated_at
            FROM secret_history
            WHERE id = :id
            LIMIT 1");
        $stmt->bindValue(':id', $historyId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC) ?: null;
        if ($row === null) {
            return null;
        }

        $oldValue = null;
        $newValue = null;

        try {
            $oldValue = isset($row['secret_value']) ? CryptoService::decrypt((string)$row['secret_value'], $this->masterKey) : null;
        } catch (\Throwable $e) {
            $oldValue = null;
        }

        if (!empty($row['new_secret_value'])) {
            try {
                $newValue = CryptoService::decrypt((string)$row['new_secret_value'], $this->masterKey);
            } catch (\Throwable $e) {
                $newValue = null;
            }
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'secret_name' => (string)($row['secret_name'] ?? ''),
            'service_id' => isset($row['service_id']) ? (string)$row['service_id'] : null,
            'rotated_at' => (string)($row['rotated_at'] ?? ''),
            'trigger_type' => (string)($row['trigger_type'] ?? 'manual'),
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ];
    }

    public function syncServiceNames(array $services): void
    {
        $stmt = $this->db->prepare("INSERT INTO service_metadata (service_id, service_name, group_name, updated_at)
            VALUES (:id, :name, (SELECT group_name FROM service_metadata WHERE service_id = :id), CURRENT_TIMESTAMP)
            ON CONFLICT(service_id) DO UPDATE SET
                service_name = excluded.service_name,
                updated_at = excluded.updated_at");

        foreach ($services as $service) {
            $id = (string)($service['id'] ?? '');
            $name = (string)($service['name'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }

            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->execute();
        }
    }

    public function getServiceGroupMap(): array
    {
        $result = $this->db->query("SELECT service_id, group_name FROM service_metadata WHERE group_name IS NOT NULL AND TRIM(group_name) != ''");
        $map = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $map[(string)$row['service_id']] = (string)$row['group_name'];
        }
        return $map;
    }

    public function getServiceCount(): int
    {
        $result = $this->db->query("SELECT COUNT(*) AS cnt FROM service_metadata");
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return (int)($row['cnt'] ?? 0);
    }

    public function getRotationsLast24h(?string $serviceId = null): int
    {
        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        if ($serviceId === null) {
            $stmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM secret_history WHERE rotated_at >= :since");
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM secret_history WHERE service_id = :sid AND rotated_at >= :since");
            $stmt->bindValue(':sid', $serviceId, SQLITE3_TEXT);
        }
        $stmt->bindValue(':since', $since, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return (int)($row['cnt'] ?? 0);
    }

    public function getAllManagedWithServiceNames(): array
    {
        $result = $this->db->query("
                 SELECT ms.secret_name, ms.service_id, ms.length, ms.encoding, ms.sync_group,
                   ms.interval_days, ms.interval_unit, ms.created_at, ms.next_rotation_at,
                   COALESCE(sm.service_name, 'Global') AS service_name,
                   (SELECT rotated_at FROM secret_history
                    WHERE secret_name = ms.secret_name
                      AND (service_id = ms.service_id OR (service_id IS NULL AND ms.service_id IS NULL))
                    ORDER BY datetime(rotated_at) DESC, id DESC LIMIT 1) AS last_rotated,
                   (SELECT rotated_at FROM secret_history
                    WHERE secret_name = ms.secret_name
                      AND (service_id = ms.service_id OR (service_id IS NULL AND ms.service_id IS NULL))
                      AND trigger_type = 'auto'
                    ORDER BY datetime(rotated_at) DESC, id DESC LIMIT 1) AS last_auto_rotated
            FROM managed_secrets ms
            LEFT JOIN service_metadata sm ON ms.service_id = sm.service_id
            ORDER BY service_name, ms.secret_name
        ");
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Advance next_rotation_at for a single secret by exactly one interval
     * (preserves clock alignment regardless of when the cron actually ran).
     */
    public function advanceNextRotationAt(string $name, ?string $serviceId, int $intervalDays, string $intervalUnit): void
    {
        $divisorMap      = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
        $intervalSeconds = $intervalDays * ($divisorMap[$intervalUnit] ?? 86400);
        if ($intervalSeconds <= 0) {
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE managed_secrets
             SET next_rotation_at = datetime(next_rotation_at, '+' || :secs || ' seconds')
             WHERE secret_name = :name
               AND (service_id = :sid OR (service_id IS NULL AND :sid IS NULL))"
        );
        $stmt->bindValue(':secs', $intervalSeconds, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':sid', $serviceId, $serviceId === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * Advance next_rotation_at for every member of a sync group.
     */
    public function advanceNextRotationAtForGroup(string $groupName, int $intervalDays, string $intervalUnit): void
    {
        $divisorMap      = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
        $intervalSeconds = $intervalDays * ($divisorMap[$intervalUnit] ?? 86400);
        if ($intervalSeconds <= 0) {
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE managed_secrets
             SET next_rotation_at = datetime(next_rotation_at, '+' || :secs || ' seconds')
             WHERE sync_group = :group"
        );
        $stmt->bindValue(':secs', $intervalSeconds, SQLITE3_INTEGER);
        $stmt->bindValue(':group', $groupName, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function setServiceGroup(string $serviceId, ?string $groupName): void
    {
        if ($serviceId === '') {
            throw new \InvalidArgumentException('Invalid service id');
        }

        if ($groupName === null || trim($groupName) === '') {
            $stmt = $this->db->prepare("UPDATE service_metadata SET group_name = NULL, updated_at = CURRENT_TIMESTAMP WHERE service_id = :id");
            $stmt->bindValue(':id', $serviceId, SQLITE3_TEXT);
            $stmt->execute();
            return;
        }

        $groupName = trim($groupName);
        if (strlen($groupName) > 64) {
            throw new \InvalidArgumentException('Group name is too long');
        }

        $stmt = $this->db->prepare("UPDATE service_metadata
            SET group_name = :group_name, updated_at = CURRENT_TIMESTAMP
            WHERE service_id = :id");
        $stmt->bindValue(':group_name', $groupName, SQLITE3_TEXT);
        $stmt->bindValue(':id', $serviceId, SQLITE3_TEXT);
        $stmt->execute();
    }
}
