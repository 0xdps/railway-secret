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
    $interval  = (int)($config['interval_days'] ?? 0);
    $timeConfig = getUnitConfig($config['interval_unit'] ?? 'day');
    $secret    = $config['secret_name'];
    $serviceId = $config['service_id'] ?: null;
    $scopeLabel = $serviceId ? "service:{$serviceId}" : 'global';

    // Skip secrets configured for manual-only rotation
    if ($interval === 0) {
        echo "  SKIP   {$secret} ({$scopeLabel}) — manual only\n";
        continue;
    }

    // Check last AUTOMATIC rotation time (ignore manual rotations for scheduling).
    // Apply a 5-minute buffer so a cron that runs slightly late is not double-triggered.
    $bufferSeconds = 5 * 60; // 5 minutes in seconds
    $bufferInUnits = $bufferSeconds / $timeConfig['divisor'];

    $lastAutoTs  = $storage->getLastAutoRotatedAt($secret, $serviceId);
    $shouldRotate = false;

    if ($lastAutoTs === null) {
        $shouldRotate = true; // Never been auto-rotated — do it now
        $reason = 'first automatic rotation';
    } else {
        $elapsed = (time() - $lastAutoTs) / $timeConfig['divisor'];

        // Rotate if we are within the interval window, allowing a 5-min early buffer
        if ($elapsed >= ($interval - $bufferInUnits)) {
            $shouldRotate = true;
            $reason = sprintf('%.1f %s since last auto rotation (interval: %d %s, buffer: 5 min)',
                $elapsed, $timeConfig['label'], $interval, $timeConfig['label']);
        } else {
            $reason = sprintf('%.1f / %d %s elapsed since last auto rotation', $elapsed, $interval, $timeConfig['label']);
        }
    }

    if ($shouldRotate) {
        echo "  ROTATE {$secret} ({$scopeLabel}) — {$reason}... ";
        try {
            if ($rotator->rotate($secret, $projectId, $environmentId, $serviceId, null, null, null, 'auto')) {
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
