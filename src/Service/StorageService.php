<?php

namespace App\Service;

use App\Database\MigrationRunner;
use Mesahub\DatabaseHandle;

class StorageService
{
    public function __construct(
        private readonly DatabaseHandle $db,
        private readonly string $masterKey,
    ) {
        $this->init();
    }

    private function init(): void
    {
        (new MigrationRunner($this->db))->run();
    }

    public function saveConfig(string $name, ?string $serviceId, array $config): bool
    {
        $syncGroup = isset($config['sync_group']) ? trim((string)$config['sync_group']) : '';

        if ($syncGroup !== '') {
            $groupPolicy = $this->getSyncGroupConfig($syncGroup);
            if ($groupPolicy === null) {
                $this->saveSyncGroupConfig($syncGroup, $config);
                $groupPolicy = $this->getSyncGroupConfig($syncGroup);
            }
            $config = array_merge($config, [
                'length'        => $groupPolicy['length'],
                'encoding'      => $groupPolicy['encoding'],
                'interval_days' => $groupPolicy['interval_days'],
                'interval_unit' => $groupPolicy['interval_unit'],
            ]);
        }

        $intervalDays = (int)($config['interval_days'] ?? 0);
        $intervalUnit = $config['interval_unit'] ?? 'day';
        $divisorMap   = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
        $intervalSecs = $intervalDays * ($divisorMap[$intervalUnit] ?? 86400);
        if ($intervalSecs > 0) {
            $now         = time();
            $bucketStart = (int)(floor($now / $intervalSecs) * $intervalSecs);
            $nextAt      = gmdate('Y-m-d H:i:s', $bucketStart + $intervalSecs);
        } else {
            $nextAt = null;
        }

        $this->db->exec("
            INSERT INTO managed_secrets
                (secret_name, service_id, length, encoding, sync_group, interval_days, interval_unit, created_at, next_rotation_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)
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
        ", [
            $name,
            $serviceId,
            $config['length'] ?? 32,
            $config['encoding'] ?? 'hex',
            $syncGroup !== '' ? $syncGroup : null,
            $intervalDays,
            $intervalUnit,
            $nextAt,
        ]);

        return true;
    }

    public function getSyncGroupConfig(string $name): ?array
    {
        $result = $this->db->query(
            "SELECT name, length, encoding, interval_days, interval_unit FROM sync_groups WHERE name = ? LIMIT 1",
            [$name]
        );
        $row = $result->rows[0] ?? null;
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

    public function saveSyncGroupConfig(string $name, array $policy): bool
    {
        $length       = max(8, min(256, (int)($policy['length'] ?? 32)));
        $encoding     = in_array($policy['encoding'] ?? 'hex', ['hex', 'base64', 'alphanumeric'], true) ? $policy['encoding'] : 'hex';
        $intervalDays = max(0, (int)($policy['interval_days'] ?? 0));
        $intervalUnit = in_array($policy['interval_unit'] ?? 'day', ['minute', 'hour', 'day'], true) ? $policy['interval_unit'] : 'day';

        $this->db->exec(
            "INSERT OR REPLACE INTO sync_groups (name, length, encoding, interval_days, interval_unit) VALUES (?, ?, ?, ?, ?)",
            [$name, $length, $encoding, $intervalDays, $intervalUnit]
        );

        $this->db->exec(
            "UPDATE managed_secrets SET length = ?, encoding = ?, interval_days = ?, interval_unit = ? WHERE sync_group = ?",
            [$length, $encoding, $intervalDays, $intervalUnit, $name]
        );

        return true;
    }

    public function deleteSyncGroup(string $name): bool
    {
        $this->db->exec("UPDATE managed_secrets SET sync_group = NULL WHERE sync_group = ?", [$name]);
        $this->db->exec("DELETE FROM sync_groups WHERE name = ?", [$name]);
        return true;
    }

    public function getSyncGroupMembers(string $groupName): array
    {
        $groupName = trim($groupName);
        if ($groupName === '') {
            return [];
        }

        $result = $this->db->query(
            "SELECT * FROM managed_secrets WHERE sync_group = ? ORDER BY service_id, secret_name",
            [$groupName]
        );
        return $result->rows;
    }

    public function getDistinctSyncGroups(): array
    {
        $result = $this->db->query("SELECT name FROM sync_groups ORDER BY name ASC");
        $groups = [];
        foreach ($result->rows as $row) {
            $groups[] = (string)($row['name'] ?? '');
        }
        return $groups;
    }

    public function getSyncGroupConfigMap(): array
    {
        $result = $this->db->query("SELECT name, length, encoding, interval_days, interval_unit FROM sync_groups ORDER BY name ASC");
        $map = [];
        foreach ($result->rows as $row) {
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
        foreach ($result->rows as $row) {
            $id   = (string)($row['service_id'] ?? '');
            $name = (string)($row['service_name'] ?? '');
            if ($id !== '' && $name !== '') {
                $map[$id] = $name;
            }
        }
        return $map;
    }

    public function getManagedSecrets(): array
    {
        $result  = $this->db->query("SELECT * FROM managed_secrets");
        $managed = [];
        foreach ($result->rows as $row) {
            $key           = ($row['service_id'] ?: 'global') . ':' . $row['secret_name'];
            $managed[$key] = $row;
        }
        return $managed;
    }

    public function deleteConfig(string $name, ?string $serviceId): bool
    {
        $this->db->exec(
            "DELETE FROM managed_secrets WHERE secret_name = ? AND (service_id = ? OR (? IS NULL AND service_id IS NULL))",
            [$name, $serviceId, $serviceId]
        );
        return true;
    }

    public function updateLatestHistoryNewValue(string $name, ?string $serviceId, string $currentPlainValue): void
    {
        $result = $this->db->query(
            "SELECT id FROM secret_history
             WHERE secret_name = ?
               AND (service_id = ? OR (service_id IS NULL AND ? IS NULL))
             ORDER BY rotated_at DESC, id DESC
             LIMIT 1",
            [$name, $serviceId, $serviceId]
        );
        $row = $result->rows[0] ?? null;
        if (!$row) {
            return;
        }

        $encrypted = CryptoService::encrypt($currentPlainValue, $this->masterKey);
        $this->db->exec(
            "UPDATE secret_history SET new_secret_value = ? WHERE id = ?",
            [$encrypted, (int)$row['id']]
        );
    }

    public function addHistory(string $name, ?string $oldPlainValue, ?string $serviceId = null, string $triggerType = 'manual'): bool
    {
        $encryptedOldValue = $oldPlainValue !== null
            ? CryptoService::encrypt($oldPlainValue, $this->masterKey)
            : null;

        $this->db->exec(
            "INSERT INTO secret_history (secret_name, service_id, secret_value, new_secret_value, trigger_type) VALUES (?, ?, ?, NULL, ?)",
            [$name, $serviceId, $encryptedOldValue, $triggerType]
        );

        // Enforce retention policy (keep last 3 per secret + scope)
        $this->db->exec(
            "DELETE FROM secret_history WHERE id NOT IN (
                SELECT id FROM secret_history
                WHERE secret_name = ?
                  AND (service_id = ? OR (service_id IS NULL AND ? IS NULL))
                ORDER BY rotated_at DESC, id DESC LIMIT 3
            ) AND secret_name = ?
              AND (service_id = ? OR (service_id IS NULL AND ? IS NULL))",
            [$name, $serviceId, $serviceId, $name, $serviceId, $serviceId]
        );

        return true;
    }

    public function getLastAutoRotatedAt(string $name, ?string $serviceId): ?int
    {
        $result = $this->db->query(
            "SELECT rotated_at FROM secret_history
             WHERE secret_name = ?
               AND (service_id = ? OR (service_id IS NULL AND ? IS NULL))
               AND trigger_type IN ('auto', 'sync-auto')
             ORDER BY rotated_at DESC, id DESC
             LIMIT 1",
            [$name, $serviceId, $serviceId]
        );
        $row = $result->rows[0] ?? null;
        if (!$row || empty($row['rotated_at'])) {
            return null;
        }
        $ts = strtotime((string)$row['rotated_at']);
        return $ts !== false ? $ts : null;
    }

    public function getHistory(string $name, ?string $serviceId = null): array
    {
        $result = $this->db->query(
            "SELECT * FROM secret_history
             WHERE secret_name = ?
               AND (service_id = ? OR (service_id IS NULL AND ? IS NULL))
             ORDER BY rotated_at DESC",
            [$name, $serviceId, $serviceId]
        );

        $history = [];
        foreach ($result->rows as $row) {
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
            $result = $this->db->query(
                "SELECT sh.id, sh.secret_name, sh.service_id, sh.trigger_type, sh.rotated_at,
                        sm.service_name
                 FROM secret_history sh
                 LEFT JOIN service_metadata sm ON sh.service_id = sm.service_id
                 ORDER BY datetime(sh.rotated_at) DESC, sh.id DESC
                 LIMIT ?",
                [$limit]
            );
        } else {
            $result = $this->db->query(
                "SELECT sh.id, sh.secret_name, sh.service_id, sh.trigger_type, sh.rotated_at,
                        sm.service_name
                 FROM secret_history sh
                 LEFT JOIN service_metadata sm ON sh.service_id = sm.service_id
                 WHERE sh.service_id = ?
                 ORDER BY datetime(sh.rotated_at) DESC, sh.id DESC
                 LIMIT ?",
                [$serviceId, $limit]
            );
        }

        return $result->rows;
    }

    public function getHistoryDetailById(int $historyId): ?array
    {
        if ($historyId <= 0) {
            return null;
        }

        $result = $this->db->query(
            "SELECT id, secret_name, service_id, secret_value, new_secret_value, trigger_type, rotated_at
             FROM secret_history
             WHERE id = ?
             LIMIT 1",
            [$historyId]
        );
        $row = $result->rows[0] ?? null;
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
            'id'           => (int)($row['id'] ?? 0),
            'secret_name'  => (string)($row['secret_name'] ?? ''),
            'service_id'   => isset($row['service_id']) ? (string)$row['service_id'] : null,
            'rotated_at'   => (string)($row['rotated_at'] ?? ''),
            'trigger_type' => (string)($row['trigger_type'] ?? 'manual'),
            'old_value'    => $oldValue,
            'new_value'    => $newValue,
        ];
    }

    public function syncServiceNames(array $services): void
    {
        foreach ($services as $service) {
            $id   = (string)($service['id'] ?? '');
            $name = (string)($service['name'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }

            $this->db->exec(
                "INSERT INTO service_metadata (service_id, service_name, group_name, updated_at)
                 VALUES (?, ?, (SELECT group_name FROM service_metadata WHERE service_id = ?), CURRENT_TIMESTAMP)
                 ON CONFLICT(service_id) DO UPDATE SET
                     service_name = excluded.service_name,
                     updated_at   = excluded.updated_at",
                [$id, $name, $id]
            );
        }
    }

    public function getServiceGroupMap(): array
    {
        $result = $this->db->query(
            "SELECT service_id, group_name FROM service_metadata WHERE group_name IS NOT NULL AND TRIM(group_name) != ''"
        );
        $map = [];
        foreach ($result->rows as $row) {
            $map[(string)$row['service_id']] = (string)$row['group_name'];
        }
        return $map;
    }

    public function getServiceCount(): int
    {
        $result = $this->db->query("SELECT COUNT(*) AS cnt FROM service_metadata");
        return (int)($result->rows[0]['cnt'] ?? 0);
    }

    public function getRotationsLast24h(?string $serviceId = null): int
    {
        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        if ($serviceId === null) {
            $result = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM secret_history WHERE rotated_at >= ?",
                [$since]
            );
        } else {
            $result = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM secret_history WHERE service_id = ? AND rotated_at >= ?",
                [$serviceId, $since]
            );
        }
        return (int)($result->rows[0]['cnt'] ?? 0);
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
        return $result->rows;
    }

    public function advanceNextRotationAt(string $name, ?string $serviceId, int $intervalDays, string $intervalUnit): void
    {
        $divisorMap      = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
        $intervalSeconds = $intervalDays * ($divisorMap[$intervalUnit] ?? 86400);
        if ($intervalSeconds <= 0) {
            return;
        }
        $this->db->exec(
            "UPDATE managed_secrets
             SET next_rotation_at = datetime(next_rotation_at, '+' || ? || ' seconds')
             WHERE secret_name = ?
               AND (service_id = ? OR (service_id IS NULL AND ? IS NULL))",
            [$intervalSeconds, $name, $serviceId, $serviceId]
        );
    }

    public function advanceNextRotationAtForGroup(string $groupName, int $intervalDays, string $intervalUnit): void
    {
        $divisorMap      = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
        $intervalSeconds = $intervalDays * ($divisorMap[$intervalUnit] ?? 86400);
        if ($intervalSeconds <= 0) {
            return;
        }
        $this->db->exec(
            "UPDATE managed_secrets
             SET next_rotation_at = datetime(next_rotation_at, '+' || ? || ' seconds')
             WHERE sync_group = ?",
            [$intervalSeconds, $groupName]
        );
    }

    public function setServiceGroup(string $serviceId, ?string $groupName): void
    {
        if ($serviceId === '') {
            throw new \InvalidArgumentException('Invalid service id');
        }

        if ($groupName === null || trim($groupName) === '') {
            $this->db->exec(
                "UPDATE service_metadata SET group_name = NULL, updated_at = CURRENT_TIMESTAMP WHERE service_id = ?",
                [$serviceId]
            );
            return;
        }

        $groupName = trim($groupName);
        if (strlen($groupName) > 64) {
            throw new \InvalidArgumentException('Group name is too long');
        }

        $this->db->exec(
            "UPDATE service_metadata SET group_name = ?, updated_at = CURRENT_TIMESTAMP WHERE service_id = ?",
            [$groupName, $serviceId]
        );
    }
}
