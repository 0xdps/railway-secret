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
            interval_days INTEGER DEFAULT 30,
            interval_unit TEXT NOT NULL DEFAULT 'day',
            last_rotated DATETIME,
            PRIMARY KEY (secret_name, service_id)
        )");

        if (!$this->tableHasColumn('managed_secrets', 'interval_unit')) {
            $this->db->exec("ALTER TABLE managed_secrets ADD COLUMN interval_unit TEXT NOT NULL DEFAULT 'day'");
        }

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_secret_history_lookup ON secret_history(secret_name, service_id, rotated_at DESC)");

        $this->db->exec("CREATE TABLE IF NOT EXISTS service_metadata (
            service_id TEXT PRIMARY KEY,
            service_name TEXT NOT NULL,
            group_name TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
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
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO managed_secrets 
            (secret_name, service_id, length, encoding, interval_days, interval_unit) 
            VALUES (:name, :sid, :len, :enc, :int, :unit)
        ");
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':sid', $serviceId, SQLITE3_TEXT);
        $stmt->bindValue(':len', $config['length'] ?? 32, SQLITE3_INTEGER);
        $stmt->bindValue(':enc', $config['encoding'] ?? 'hex', SQLITE3_TEXT);
        $stmt->bindValue(':int', $config['interval_days'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':unit', $config['interval_unit'] ?? 'day', SQLITE3_TEXT);
        
        return (bool)$stmt->execute();
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
               AND trigger_type = 'auto'
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
            SELECT ms.secret_name, ms.service_id, ms.length, ms.encoding,
                   ms.interval_days, ms.interval_unit,
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

    public function setServiceGroup(string $serviceId, ?string $groupName): void
    {
        $serviceId = trim($serviceId);
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
