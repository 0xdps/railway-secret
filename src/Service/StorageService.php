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

        $this->db->exec("CREATE TABLE IF NOT EXISTS managed_secrets (
            secret_name TEXT NOT NULL,
            service_id TEXT,
            length INTEGER DEFAULT 32,
            encoding TEXT DEFAULT 'hex',
            interval_days INTEGER DEFAULT 30,
            last_rotated DATETIME,
            PRIMARY KEY (secret_name, service_id)
        )");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_secret_history_lookup ON secret_history(secret_name, service_id, rotated_at DESC)");
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
            (secret_name, service_id, length, encoding, interval_days) 
            VALUES (:name, :sid, :len, :enc, :int)
        ");
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':sid', $serviceId, SQLITE3_TEXT);
        $stmt->bindValue(':len', $config['length'] ?? 32, SQLITE3_INTEGER);
        $stmt->bindValue(':enc', $config['encoding'] ?? 'hex', SQLITE3_TEXT);
        $stmt->bindValue(':int', $config['interval_days'] ?? 0, SQLITE3_INTEGER);
        
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

    public function addHistory(string $name, string $plainValue, ?string $serviceId = null): bool
    {
        $encryptedValue = CryptoService::encrypt($plainValue, $this->masterKey);
        
        $stmt = $this->db->prepare("INSERT INTO secret_history (secret_name, service_id, secret_value) VALUES (:name, :sid, :val)");
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':sid', $serviceId, $serviceId === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':val', $encryptedValue, SQLITE3_TEXT);
        $result = $stmt->execute();

        // Enforce retention policy (keep last 3 per secret + scope)
        $cleanup = $this->db->prepare("DELETE FROM secret_history WHERE id IN (
            SELECT id FROM secret_history
            WHERE secret_name = :name
              AND (service_id = :sid OR (service_id IS NULL AND :sid IS NULL))
            ORDER BY rotated_at DESC LIMIT -1 OFFSET 3
        )");
        $cleanup->bindValue(':name', $name, SQLITE3_TEXT);
        $cleanup->bindValue(':sid', $serviceId, $serviceId === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $cleanup->execute();

        return (bool)$result;
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
            $row['secret_value'] = CryptoService::decrypt($row['secret_value'], $this->masterKey);
            $history[] = $row;
        }
        return $history;
    }
}
