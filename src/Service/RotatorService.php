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

        if ($success && $oldValue !== null) {
            // 5. Back-fill the new_secret_value of the PREVIOUS history row now that
            //    we know what value was set during that rotation (= current value = oldValue).
            $this->storage->updateLatestHistoryNewValue($keyName, $serviceId, $oldValue);

            // 6. Record this rotation: store only the old value; new value will be
            //    back-filled the next time this secret is rotated.
            $this->storage->addHistory($keyName, $oldValue, $serviceId, $triggerType);
            return true;
        }

        return $success;
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
}
