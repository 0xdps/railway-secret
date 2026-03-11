<?php

namespace App\Service;

class RotatorService
{
    private RailwayClient $railway;
    private StorageService $storage;

    public function __construct(RailwayClient $railway, StorageService $storage)
    {
        $this->railway = $railway;
        $this->storage = $storage;
    }

    public function rotate(string $keyName, string $projectId, string $environmentId, ?string $serviceId = null, ?string $manualValue = null, ?int $length = null, ?string $encoding = null, string $triggerType = 'manual'): bool
    {
        // 1. Get dynamic config from storage or use defaults
        $managed = $this->storage->getManagedSecrets();
        $key = ($serviceId ?: 'global') . ':' . $keyName;
        $config = $managed[$key] ?? ['length' => 32, 'encoding' => 'hex'];

        $syncGroup = isset($config['sync_group']) ? trim((string)$config['sync_group']) : '';
        if ($syncGroup !== '') {
            return $this->rotateSyncGroup($syncGroup, $projectId, $environmentId, $manualValue, $triggerType, $length, $encoding);
        }
        
        // 2. Apply overrides if provided (for one-off rotations)
        if ($length) $config['length'] = $length;
        if ($encoding) $config['encoding'] = $encoding;
        
        // 3. Fetch current value from Railway FIRST
        $currentVars = $this->railway->getVariables($projectId, $environmentId, $serviceId);
        $oldValue = $currentVars[$keyName] ?? null;

        // 4. Generate new secret or use manual value
        $newValue = $manualValue ?? CryptoService::generateSecret($config['length'], $config['encoding']);

        // 4. Update Railway
        $success = $this->railway->upsertVariable($projectId, $environmentId, $keyName, $newValue, $serviceId);

        if ($success) {
            // 5. Back-fill the new_secret_value of the PREVIOUS history row now that
            //    we know what value was set during that rotation (= current value = oldValue).
            if ($oldValue !== null) {
                $this->storage->updateLatestHistoryNewValue($keyName, $serviceId, $oldValue);
            }

            // 6. Record this rotation: store the old value (may be null for first-ever
            //    rotation); new value will be back-filled on the next rotation.
            $this->storage->addHistory($keyName, $oldValue, $serviceId, $triggerType);

            // 7. Advance the scheduled next-rotation timestamp (auto only).
            if ($triggerType === 'auto') {
                $this->storage->advanceNextRotationAt(
                    $keyName, $serviceId,
                    (int)($config['interval_days'] ?? 0),
                    (string)($config['interval_unit'] ?? 'day')
                );
            }
        }

        return $success;
    }

    /**
     * Rotate all managed secrets in a sync group using exactly one generated value.
     * Each service scope still receives one Railway upsert call (one redeploy per scope).
     */
    public function rotateSyncGroup(
        string $groupName,
        string $projectId,
        string $environmentId,
        ?string $manualValue = null,
        string $triggerType = 'manual',
        ?int $lengthOverride = null,
        ?string $encodingOverride = null
    ): bool {
        $members = $this->storage->getSyncGroupMembers($groupName);
        if (empty($members)) {
            return false;
        }

        $historyTrigger = str_starts_with($triggerType, 'sync-')
            ? $triggerType
            : ($triggerType === 'auto' ? 'sync-auto' : 'sync-manual');

        $first = $members[0];
        $length   = $lengthOverride   ?? (int)($first['length'] ?? 32);
        $encoding = $encodingOverride ?? (string)($first['encoding'] ?? 'hex');
        $newValue = $manualValue ?? CryptoService::generateSecret($length, $encoding);

        $byScope = [];
        foreach ($members as $member) {
            $scope = (($member['service_id'] ?? '') !== '' ? (string)$member['service_id'] : '__global__');
            $byScope[$scope][] = $member;
        }

        foreach ($byScope as $scope => $scopeMembers) {
            $serviceId = $scope === '__global__' ? null : $scope;
            $currentVars = $this->railway->getVariables($projectId, $environmentId, $serviceId);

            $vars = [];
            foreach ($scopeMembers as $member) {
                $vars[(string)$member['secret_name']] = $newValue;
            }

            $success = $this->railway->upsertVariables($projectId, $environmentId, $vars, $serviceId);
            if (!$success) {
                return false;
            }

            foreach ($scopeMembers as $member) {
                $name = (string)$member['secret_name'];
                $oldValue = $currentVars[$name] ?? null;
                if ($oldValue !== null) {
                    $this->storage->updateLatestHistoryNewValue($name, $serviceId, $oldValue);
                }
                $this->storage->addHistory($name, $oldValue, $serviceId, $historyTrigger);
            }
        }

        // Advance the scheduled next-rotation timestamp for every group member (auto only).
        if ($historyTrigger === 'sync-auto') {
            $this->storage->advanceNextRotationAtForGroup(
                $groupName,
                (int)($first['interval_days'] ?? 0),
                (string)($first['interval_unit'] ?? 'day')
            );
        }

        return true;
    }

    /**
     * Rollback to a previous version
     */
    public function rollback(string $keyName, string $projectId, string $environmentId, ?string $serviceId = null): bool
    {
        $history = $this->storage->getHistory($keyName, $serviceId);
        if (empty($history)) {
            return false;
        }

        $previousValue = $history[0]['secret_value'];
        return $this->railway->upsertVariable($projectId, $environmentId, $keyName, $previousValue, $serviceId);
    }

    /**
     * Rotate multiple secrets with one Railway API call per service scope so that
     * only one redeployment is triggered per service (instead of one per secret).
     *
     * @param  array  $dueSecrets  Each element must contain: secret_name, service_id,
     *                              length, encoding, trigger_type.
     * @return array  [ 'secret_name' => 'success' | 'error: …', … ]
     */
    public function rotateBatch(array $dueSecrets, string $projectId, string $environmentId): array
    {
        // Group secrets by service scope so all variables for the same service
        // are pushed in a single variableCollectionUpsert call.
        $byScope = [];
        foreach ($dueSecrets as $item) {
            $scope = $item['service_id'] ?: '__global__';
            $byScope[$scope][] = $item;
        }

        $results = [];

        foreach ($byScope as $scope => $secrets) {
            $serviceId = $scope === '__global__' ? null : $scope;

            // Fetch current variable values once per scope (needed for history).
            try {
                $currentVars = $this->railway->getVariables($projectId, $environmentId, $serviceId);
            } catch (\Exception $e) {
                foreach ($secrets as $item) {
                    $results[$item['secret_name']] = 'error: ' . $e->getMessage();
                }
                continue;
            }

            // Generate a new value for every secret in this scope.
            $newValues = [];
            foreach ($secrets as $item) {
                $name     = $item['secret_name'];
                $length   = (int)($item['length'] ?? 32);
                $encoding = $item['encoding'] ?? 'hex';
                $newValues[$name] = CryptoService::generateSecret($length, $encoding);
            }

            // One batch upsert → one Railway redeploy for the whole scope.
            try {
                $success = $this->railway->upsertVariables($projectId, $environmentId, $newValues, $serviceId);
            } catch (\Exception $e) {
                foreach ($secrets as $item) {
                    $results[$item['secret_name']] = 'error: ' . $e->getMessage();
                }
                continue;
            }

            if (!$success) {
                foreach ($secrets as $item) {
                    $results[$item['secret_name']] = 'error: batch upsert returned false';
                }
                continue;
            }

            // Record history for each rotated secret.
            foreach ($secrets as $item) {
                $name     = $item['secret_name'];
                $oldValue = $currentVars[$name] ?? null;
                $trigger  = $item['trigger_type'] ?? 'auto';

                // Back-fill new_secret_value on the previous history row.
                if ($oldValue !== null) {
                    $this->storage->updateLatestHistoryNewValue($name, $serviceId, $oldValue);
                }

                $this->storage->addHistory($name, $oldValue, $serviceId, $trigger);
                $this->storage->advanceNextRotationAt(
                    $name, $serviceId,
                    (int)($item['interval_days'] ?? 0),
                    (string)($item['interval_unit'] ?? 'day')
                );
                $results[$name] = 'success';
            }
        }

        return $results;
    }
}
