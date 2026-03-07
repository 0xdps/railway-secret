<?php

require_once __DIR__ . '/bootstrap.php';

use App\Service\RailwayClient;
use App\Service\StorageService;
use App\Service\RotatorService;

function getRequiredEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Missing required configuration: {$name}");
    }

    return $value;
}

// Ensure we are in CLI
if (php_sapi_name() !== 'cli') {
    die("Access Denied");
}

try {
    $masterKey = getRequiredEnv('MASTER_KEY');
    $railwayToken = getRequiredEnv('RAILWAY_TOKEN');
    
    // Get rotation time configuration
    $timeConfig = getRotationTimeConfig();

    // Injected automatically by Railway
    $projectId = getenv('RAILWAY_PROJECT_ID') ?: getenv('PROJECT_ID');
    $environmentId = getenv('RAILWAY_ENVIRONMENT_ID') ?: getenv('ENVIRONMENT_ID');
    if ($projectId === false || trim((string)$projectId) === '') {
        throw new RuntimeException('Missing required configuration: RAILWAY_PROJECT_ID or PROJECT_ID');
    }
    if ($environmentId === false || trim((string)$environmentId) === '') {
        throw new RuntimeException('Missing required configuration: RAILWAY_ENVIRONMENT_ID or ENVIRONMENT_ID');
    }

    $dbPath = __DIR__ . '/storage/db/secrets.sqlite';

    $railway = new RailwayClient($railwayToken);
    $storage = new StorageService($dbPath, $masterKey);
    $rotator = new RotatorService($railway, $storage);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Configuration error: ' . $e->getMessage() . "\n");
    exit(1);
}

// Read ALL managed secrets from the DB (same source as the dashboard)
$managed = $storage->getManagedSecrets();

if (empty($managed)) {
    echo "No managed secrets configured. Add secrets via the dashboard first.\n";
    exit(0);
}

echo "Starting scheduled rotations (" . date('Y-m-d H:i:s') . ")...\n";
echo str_repeat('-', 60) . "\n";

foreach ($managed as $key => $config) {
    $interval = (int)($config['interval_days'] ?? 0);
    $secret   = $config['secret_name'];
    $serviceId = $config['service_id'] ?: null;
    $scopeLabel = $serviceId ? "service:{$serviceId}" : 'global';

    // Skip secrets configured for manual-only rotation
    if ($interval === 0) {
        echo "  SKIP   {$secret} ({$scopeLabel}) — manual only\n";
        continue;
    }

    // Check last rotation time from history
    $history = $storage->getHistory($secret, $serviceId);
    $shouldRotate = false;

    if (empty($history)) {
        $shouldRotate = true; // Never been rotated — do it now
        $reason = 'first rotation';
    } else {
        $lastRotated = strtotime($history[0]['rotated_at']);
        $elapsed = (time() - $lastRotated) / $timeConfig['divisor'];

        if ($elapsed >= $interval) {
            $shouldRotate = true;
            $reason = sprintf('%.1f %s since last rotation (interval: %d %s)', $elapsed, $timeConfig['label'], $interval, $timeConfig['label']);
        } else {
            $reason = sprintf('%.1f / %d %s elapsed', $elapsed, $interval, $timeConfig['label']);
        }
    }

    if ($shouldRotate) {
        echo "  ROTATE {$secret} ({$scopeLabel}) — {$reason}... ";
        try {
            if ($rotator->rotate($secret, $projectId, $environmentId, $serviceId)) {
                echo "OK\n";
            } else {
                echo "FAILED (rotator returned false)\n";
            }
        } catch (\Exception $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    } else {
        echo "  SKIP   {$secret} ({$scopeLabel}) — {$reason}\n";
    }
}

echo str_repeat('-', 60) . "\n";
echo "Done.\n";
