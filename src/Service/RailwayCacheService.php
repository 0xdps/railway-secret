<?php

namespace App\Service;

use Mesahub\DatabaseHandle;

class RailwayCacheService
{
    public function __construct(
        private readonly DatabaseHandle $db,
        private readonly ?string $encryptionKey = null,
    ) {
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
        $result = $this->db->query(
            "SELECT payload_json, expires_at FROM railway_cache WHERE cache_key = ? LIMIT 1",
            [$key]
        );
        $row = $result->rows[0] ?? null;

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
        $result = $this->db->query(
            "SELECT fetched_at, expires_at FROM railway_cache WHERE cache_key = ? LIMIT 1",
            [$key]
        );
        $row = $result->rows[0] ?? null;
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
        $now         = time();
        $expiresAt   = $now + max(1, $ttlSeconds);
        $payloadJson = json_encode($payload);
        if (!is_string($payloadJson)) {
            throw new \RuntimeException('Unable to encode cache payload');
        }

        if ($this->encryptionKey !== null) {
            $payloadJson = CryptoService::encrypt($payloadJson, $this->encryptionKey);
        }

        $this->db->exec(
            "INSERT INTO railway_cache (cache_key, payload_json, fetched_at, expires_at)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(cache_key) DO UPDATE SET
                 payload_json = excluded.payload_json,
                 fetched_at   = excluded.fetched_at,
                 expires_at   = excluded.expires_at",
            [$key, $payloadJson, $now, $expiresAt]
        );
    }

    public function delete(string $key): void
    {
        $this->db->exec("DELETE FROM railway_cache WHERE cache_key = ?", [$key]);
    }

    public function deletePrefix(string $prefix): void
    {
        $this->db->exec(
            "DELETE FROM railway_cache WHERE cache_key LIKE ?",
            [$prefix . '%']
        );
    }

    public function clear(): void
    {
        $this->db->exec("DELETE FROM railway_cache");
    }
}
