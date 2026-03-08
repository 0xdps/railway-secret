<?php

namespace App\Service;

use SQLite3;

class RailwayCacheService
{
    private SQLite3 $db;
    private ?string $encryptionKey;

    public function __construct(string $dbPath, ?string $encryptionKey = null)
    {
        $this->encryptionKey = ($encryptionKey !== '' && $encryptionKey !== null) ? $encryptionKey : null;
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir) && !mkdir($dbDir, 0775, true) && !is_dir($dbDir)) {
            throw new \RuntimeException("Unable to create cache directory: {$dbDir}");
        }

        $this->db = new SQLite3($dbPath);
        $this->db->busyTimeout(5000);
        $this->init();
    }

    private function init(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS railway_cache (
            cache_key TEXT PRIMARY KEY,
            payload_json TEXT NOT NULL,
            fetched_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL
        )");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_railway_cache_expiry ON railway_cache(expires_at)");
    }

    public function get(string $key): ?array
    {
        $stmt = $this->db->prepare("SELECT payload_json, expires_at FROM railway_cache WHERE cache_key = :key LIMIT 1");
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res->fetchArray(SQLITE3_ASSOC) ?: null;

        if (!$row) {
            return null;
        }

        if ((int)$row['expires_at'] < time()) {
            $this->delete($key);
            return null;
        }

        $raw = (string)$row['payload_json'];
        if ($this->encryptionKey !== null) {
            $decrypted = CryptoService::decrypt($raw, $this->encryptionKey);
            if ($decrypted === null) {
                // Stale entry written before encryption was enabled — purge and force re-fetch.
                $this->delete($key);
                return null;
            }
            $raw = $decrypted;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function getInfo(string $key): ?array
    {
        $stmt = $this->db->prepare("SELECT fetched_at, expires_at FROM railway_cache WHERE cache_key = :key LIMIT 1");
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res->fetchArray(SQLITE3_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        return [
            'fetched_at' => (int)($row['fetched_at'] ?? 0),
            'expires_at' => (int)($row['expires_at'] ?? 0),
        ];
    }

    public function put(string $key, array $payload, int $ttlSeconds): void
    {
        $now = time();
        $expiresAt = $now + max(1, $ttlSeconds);
        $payloadJson = json_encode($payload);
        if (!is_string($payloadJson)) {
            throw new \RuntimeException('Unable to encode cache payload');
        }

        if ($this->encryptionKey !== null) {
            $payloadJson = CryptoService::encrypt($payloadJson, $this->encryptionKey);
        }

        $stmt = $this->db->prepare("INSERT INTO railway_cache (cache_key, payload_json, fetched_at, expires_at)
            VALUES (:key, :payload, :fetched_at, :expires_at)
            ON CONFLICT(cache_key) DO UPDATE SET
                payload_json = excluded.payload_json,
                fetched_at = excluded.fetched_at,
                expires_at = excluded.expires_at");
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':payload', $payloadJson, SQLITE3_TEXT);
        $stmt->bindValue(':fetched_at', $now, SQLITE3_INTEGER);
        $stmt->bindValue(':expires_at', $expiresAt, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function delete(string $key): void
    {
        $stmt = $this->db->prepare("DELETE FROM railway_cache WHERE cache_key = :key");
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function deletePrefix(string $prefix): void
    {
        $stmt = $this->db->prepare("DELETE FROM railway_cache WHERE cache_key LIKE :prefix");
        $stmt->bindValue(':prefix', $prefix . '%', SQLITE3_TEXT);
        $stmt->execute();
    }

    public function clear(): void
    {
        $this->db->exec("DELETE FROM railway_cache");
    }
}
