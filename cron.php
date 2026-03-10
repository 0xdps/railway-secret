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

// Pass 1 — determine which secrets are due for rotation
$dueSecrets  = [];   // standalone (no sync group)
$dueSyncGroups = []; // sync group name => true (deduplicated)

foreach ($managed as $key => $config) {
    $interval   = (int)($config['interval_days'] ?? 0);
    $timeConfig = getUnitConfig($config['interval_unit'] ?? 'day');
    $secret     = $config['secret_name'];
    $serviceId  = $config['service_id'] ?: null;
    $scopeLabel = $serviceId ? "service:{$serviceId}" : 'global';
    $syncGroup  = trim((string)($config['sync_group'] ?? ''));

    // Skip secrets configured for manual-only rotation
    if ($interval === 0) {
        echo "  SKIP   {$secret} ({$scopeLabel}) — manual only\n";
        continue;
    }

    // Check last AUTOMATIC rotation time (ignore manual rotations for scheduling).
    // Apply a 5-minute buffer so a cron that runs slightly late is not double-triggered.
    $bufferSeconds = 5 * 60;
    $bufferInUnits = $bufferSeconds / $timeConfig['divisor'];

    $lastAutoTs = $storage->getLastAutoRotatedAt($secret, $serviceId);

    if ($lastAutoTs === null) {
        $isDue  = true;
        $reason = 'first automatic rotation';
    } else {
        $elapsed = (time() - $lastAutoTs) / $timeConfig['divisor'];
        $isDue   = $elapsed >= ($interval - $bufferInUnits);
        $reason  = $isDue
            ? sprintf('%.1f %s since last auto rotation (interval: %d %s, buffer: 5 min)',
                $elapsed, $timeConfig['label'], $interval, $timeConfig['label'])
            : sprintf('%.1f / %d %s elapsed since last auto rotation', $elapsed, $interval, $timeConfig['label']);
    }

    if ($isDue) {
        if ($syncGroup !== '') {
            // Track at the group level — all members share one rotation call
            $dueSyncGroups[$syncGroup] = true;
        } else {
            $dueSecrets[] = array_merge($config, [
                'service_id'   => $serviceId,
                'trigger_type' => 'auto',
                '_reason'      => $reason,
                '_scope'       => $scopeLabel,
            ]);
        }
    } else {
        echo "  SKIP   {$secret} ({$scopeLabel}) — {$reason}\n";
    }
}

echo str_repeat('-', 60) . "\n";

if (empty($dueSecrets) && empty($dueSyncGroups)) {
    echo "No secrets are due for rotation.\n";
    echo "Done.\n";
    exit(0);
}

// Show what will be rotated, grouped by scope (standalone) and by group (sync)
$grouped = [];
foreach ($dueSecrets as $item) {
    $grouped[$item['_scope']][] = $item['secret_name'];
}
foreach ($grouped as $scope => $names) {
    $count = count($names);
    $noun  = $count === 1 ? 'secret' : 'secrets';
    echo "  QUEUED {$count} {$noun} in {$scope} — 1 redeploy for this scope\n";
    foreach ($names as $name) {
        echo "         • {$name}\n";
    }
}
foreach (array_keys($dueSyncGroups) as $groupName) {
    echo "  QUEUED sync-group [{$groupName}] — 1 shared value for all members\n";
}
echo str_repeat('-', 60) . "\n";

// Pass 2a — rotate standalone secrets (one Railway call per scope)
$results = !empty($dueSecrets)
    ? $rotator->rotateBatch($dueSecrets, $projectId, $environmentId)
    : [];

$totalOk  = 0;
$totalErr = 0;

foreach ($results as $name => $result) {
    if ($result === 'success') {
        echo "  OK     {$name}\n";
        $totalOk++;
    } else {
        echo "  ERROR  {$name}: {$result}\n";
        $totalErr++;
    }
}

// Pass 2b — rotate each due sync group with a single shared value
foreach (array_keys($dueSyncGroups) as $groupName) {
    try {
        $ok = $rotator->rotateSyncGroup($groupName, $projectId, $environmentId, null, 'sync-auto');
        if ($ok) {
            $members = $storage->getSyncGroupMembers($groupName);
            echo "  OK     sync-group [{$groupName}] (" . count($members) . " members)\n";
            $totalOk += count($members);
        } else {
            echo "  ERROR  sync-group [{$groupName}]: rotation returned false\n";
            $totalErr++;
        }
    } catch (\Throwable $e) {
        echo "  ERROR  sync-group [{$groupName}]: " . $e->getMessage() . "\n";
        $totalErr++;
    }
}

echo str_repeat('-', 60) . "\n";
$summary = "Done. {$totalOk} rotated";
if ($totalErr > 0) {
    $summary .= ", {$totalErr} failed";
}
echo $summary . ".\n";
