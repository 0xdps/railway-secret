<?php

namespace App\Service;

class RotatorService
{
    private RailwayClient $railway;
    private StorageService $storage;
    private WebhookNotifier $notifier;
    private ?string $lockOwner = null;

    public function __construct(RailwayClient $railway, StorageService $storage, ?WebhookNotifier $notifier = null)
    {
        $this->railway = $railway;
        $this->storage = $storage;
        $this->notifier = $notifier ?? new WebhookNotifier(null);
    }

    public function rotate(string $keyName, string $projectId, string $environmentId, ?string $serviceId = null, ?string $manualValue = null, ?int $length = null, ?string $encoding = null, string $triggerType = 'manual'): bool
    {
        return $this->withLock(fn () => $this->rotateUnlocked(
            $keyName, $projectId, $environmentId, $serviceId, $manualValue, $length, $encoding, $triggerType
        ));
    }

    private function rotateUnlocked(string $keyName, string $projectId, string $environmentId, ?string $serviceId, ?string $manualValue, ?int $length, ?string $encoding, string $triggerType): bool
    {
        $managed = $this->storage->getManagedSecrets();
        $key = ($serviceId ?: 'global') . ':' . $keyName;
        $config = $managed[$key] ?? ['length' => 32, 'encoding' => 'hex'];

        $syncGroup = isset($config['sync_group']) ? trim((string)$config['sync_group']) : '';
        if ($syncGroup !== '') {
            $result = $this->rotateSyncGroupUnlocked(
                $syncGroup, $projectId, $environmentId, $manualValue, $triggerType, $length, $encoding
            );
            if ($result->partial) {
                throw new SyncGroupPartialException($result);
            }
            return $result->ok;
        }

        if ($length) $config['length'] = $length;
        if ($encoding) $config['encoding'] = $encoding;

        $currentVars = $this->railway->getVariables($projectId, $environmentId, $serviceId);
        $oldValue = $currentVars[$keyName] ?? null;
        $newValue = $manualValue ?? CryptoService::generateSecret($config['length'], $config['encoding']);

        $success = $this->railway->upsertVariable($projectId, $environmentId, $keyName, $newValue, $serviceId);
        if (!$success) {
            $this->notifier->notify('rotation_failed', [
                'secret'     => $keyName,
                'service_id' => $serviceId ?: 'global',
                'trigger'    => $triggerType,
            ]);
            return false;
        }

        if ($oldValue !== null) {
            $this->storage->updateLatestHistoryNewValue($keyName, $serviceId, $oldValue);
        }
        $this->storage->addHistory($keyName, $oldValue, $serviceId, $triggerType);

        if ($triggerType === 'auto') {
            $this->storage->advanceNextRotationAt(
                $keyName, $serviceId,
                (int)($config['interval_days'] ?? 0),
                (string)($config['interval_unit'] ?? 'day')
            );
        }

        return true;
    }

    /**
     * Rotate all managed secrets in a sync group using exactly one generated value.
     * If a later scope fails, earlier scopes are restored to their previous values.
     * A scope that cannot be restored is reported as a partial apply.
     */
    public function rotateSyncGroup(
        string $groupName,
        string $projectId,
        string $environmentId,
        ?string $manualValue = null,
        string $triggerType = 'manual',
        ?int $lengthOverride = null,
        ?string $encodingOverride = null
    ): SyncGroupResult {
        return $this->withLock(fn () => $this->rotateSyncGroupUnlocked(
            $groupName, $projectId, $environmentId, $manualValue, $triggerType, $lengthOverride, $encodingOverride
        ));
    }

    private function rotateSyncGroupUnlocked(
        string $groupName,
        string $projectId,
        string $environmentId,
        ?string $manualValue,
        string $triggerType,
        ?int $lengthOverride,
        ?string $encodingOverride
    ): SyncGroupResult {
        $members = $this->storage->getSyncGroupMembers($groupName);
        if (empty($members)) {
            return new SyncGroupResult(false, false, [], [$groupName], []);
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

        /** @var array<string, array{serviceId: ?string, members: array, currentVars: array}> $applied */
        $applied = [];
        $failed = [];

        foreach ($byScope as $scope => $scopeMembers) {
            $serviceId = $scope === '__global__' ? null : $scope;
            try {
                $currentVars = $this->railway->getVariables($projectId, $environmentId, $serviceId);
                $vars = [];
                foreach ($scopeMembers as $member) {
                    $vars[(string)$member['secret_name']] = $newValue;
                }
                $success = $this->railway->upsertVariables($projectId, $environmentId, $vars, $serviceId);
            } catch (\Throwable $e) {
                $success = false;
            }

            if (!$success) {
                $failed[] = $scope === '__global__' ? 'global' : $scope;
                break;
            }

            $applied[$scope] = [
                'serviceId'   => $serviceId,
                'members'     => $scopeMembers,
                'currentVars' => $currentVars,
            ];
        }

        if ($failed !== []) {
            $restored = [];
            $stillUpdated = [];
            foreach ($applied as $scope => $snap) {
                $old = [];
                foreach ($snap['members'] as $member) {
                    $name = (string)$member['secret_name'];
                    if (array_key_exists($name, $snap['currentVars']) && $snap['currentVars'][$name] !== null) {
                        $old[$name] = $snap['currentVars'][$name];
                    }
                }
                $label = $scope === '__global__' ? 'global' : $scope;
                if ($old === []) {
                    $stillUpdated[] = $label;
                    continue;
                }
                try {
                    $rolledBack = $this->railway->upsertVariables($projectId, $environmentId, $old, $snap['serviceId']);
                } catch (\Throwable $e) {
                    $rolledBack = false;
                }
                if ($rolledBack) {
                    $restored[] = $label;
                } else {
                    $stillUpdated[] = $label;
                    foreach ($snap['members'] as $member) {
                        $name = (string)$member['secret_name'];
                        $oldValue = $snap['currentVars'][$name] ?? null;
                        if ($oldValue !== null) {
                            $this->storage->updateLatestHistoryNewValue($name, $snap['serviceId'], $oldValue);
                        }
                        $this->storage->addHistory($name, $oldValue, $snap['serviceId'], $historyTrigger);
                    }
                }
            }

            $partial = $stillUpdated !== [];
            if ($partial && $historyTrigger === 'sync-auto') {
                $this->storage->advanceNextRotationAtForGroup(
                    $groupName,
                    (int)($first['interval_days'] ?? 0),
                    (string)($first['interval_unit'] ?? 'day')
                );
            }
            $this->notifier->notify($partial ? 'rotation_partial' : 'rotation_failed', [
                'group'    => $groupName,
                'failed'   => $failed,
                'restored' => $restored,
                'updated'  => $stillUpdated,
                'trigger'  => $historyTrigger,
            ]);
            return new SyncGroupResult(false, $partial, $stillUpdated, $failed, $restored);
        }

        foreach ($applied as $snap) {
            foreach ($snap['members'] as $member) {
                $name = (string)$member['secret_name'];
                $oldValue = $snap['currentVars'][$name] ?? null;
                if ($oldValue !== null) {
                    $this->storage->updateLatestHistoryNewValue($name, $snap['serviceId'], $oldValue);
                }
                $this->storage->addHistory($name, $oldValue, $snap['serviceId'], $historyTrigger);
            }
        }

        if ($historyTrigger === 'sync-auto') {
            $this->storage->advanceNextRotationAtForGroup(
                $groupName,
                (int)($first['interval_days'] ?? 0),
                (string)($first['interval_unit'] ?? 'day')
            );
        }

        return new SyncGroupResult(true, false, array_map(
            static fn (string $scope): string => $scope === '__global__' ? 'global' : $scope,
            array_keys($applied)
        ), [], []);
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
     * Rotate multiple secrets with one Railway API call per service scope.
     *
     * @param  array $dueSecrets  Each element must contain secret_name, service_id, length, encoding, trigger_type.
     * @return list<array{secret_name: string, service_id: ?string, result: string}>
     */
    public function rotateBatch(array $dueSecrets, string $projectId, string $environmentId): array
    {
        return $this->withLock(fn () => $this->rotateBatchUnlocked($dueSecrets, $projectId, $environmentId));
    }

    private function rotateBatchUnlocked(array $dueSecrets, string $projectId, string $environmentId): array
    {
        $byScope = [];
        foreach ($dueSecrets as $item) {
            $scope = ($item['service_id'] ?? '') !== '' && $item['service_id'] !== null
                ? (string)$item['service_id']
                : '__global__';
            $byScope[$scope][] = $item;
        }

        $results = [];

        foreach ($byScope as $scope => $secrets) {
            $serviceId = $scope === '__global__' ? null : $scope;

            try {
                $currentVars = $this->railway->getVariables($projectId, $environmentId, $serviceId);
            } catch (\Exception $e) {
                foreach ($secrets as $item) {
                    $results[] = $this->batchRow($item, $serviceId, 'error: ' . $e->getMessage());
                }
                $this->notifier->notify('rotation_failed', [
                    'service_id' => $serviceId ?: 'global',
                    'secrets'    => array_column($secrets, 'secret_name'),
                    'error'      => $e->getMessage(),
                ]);
                continue;
            }

            $newValues = [];
            foreach ($secrets as $item) {
                $name     = $item['secret_name'];
                $length   = (int)($item['length'] ?? 32);
                $encoding = $item['encoding'] ?? 'hex';
                $newValues[$name] = CryptoService::generateSecret($length, $encoding);
            }

            try {
                $success = $this->railway->upsertVariables($projectId, $environmentId, $newValues, $serviceId);
            } catch (\Exception $e) {
                foreach ($secrets as $item) {
                    $results[] = $this->batchRow($item, $serviceId, 'error: ' . $e->getMessage());
                }
                $this->notifier->notify('rotation_failed', [
                    'service_id' => $serviceId ?: 'global',
                    'secrets'    => array_column($secrets, 'secret_name'),
                    'error'      => $e->getMessage(),
                ]);
                continue;
            }

            if (!$success) {
                foreach ($secrets as $item) {
                    $results[] = $this->batchRow($item, $serviceId, 'error: batch upsert returned false');
                }
                $this->notifier->notify('rotation_failed', [
                    'service_id' => $serviceId ?: 'global',
                    'secrets'    => array_column($secrets, 'secret_name'),
                ]);
                continue;
            }

            foreach ($secrets as $item) {
                $name     = $item['secret_name'];
                $oldValue = $currentVars[$name] ?? null;
                $trigger  = $item['trigger_type'] ?? 'auto';

                if ($oldValue !== null) {
                    $this->storage->updateLatestHistoryNewValue($name, $serviceId, $oldValue);
                }

                $this->storage->addHistory($name, $oldValue, $serviceId, $trigger);
                $this->storage->advanceNextRotationAt(
                    $name, $serviceId,
                    (int)($item['interval_days'] ?? 0),
                    (string)($item['interval_unit'] ?? 'day')
                );
                $results[] = $this->batchRow($item, $serviceId, 'success');
            }
        }

        return $results;
    }

    private function batchRow(array $item, ?string $serviceId, string $result): array
    {
        return [
            'secret_name' => (string)$item['secret_name'],
            'service_id'  => $serviceId,
            'result'      => $result,
        ];
    }

    private function withLock(callable $fn): mixed
    {
        if ($this->lockOwner !== null) {
            return $fn();
        }

        $owner = bin2hex(random_bytes(16));
        if (!$this->storage->tryAcquireRotationLock($owner)) {
            throw new RotationBusyException('A rotation is already in progress');
        }

        $this->lockOwner = $owner;
        try {
            return $fn();
        } finally {
            $this->storage->releaseRotationLock($owner);
            $this->lockOwner = null;
        }
    }
}
