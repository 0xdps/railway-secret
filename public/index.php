<?php

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $assetPath = __DIR__ . $requestPath;
    if (is_file($assetPath)) {
        return false;
    }
}

$earlyPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$earlyMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (($earlyPath === '/health' || $earlyPath === '/healthz') && ($earlyMethod === 'GET' || $earlyMethod === 'HEAD')) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'status' => 'healthy',
        'timestamp' => gmdate('c'),
    ]);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

use App\Service\RailwayClient;
use App\Service\RailwayCacheService;
use App\Service\StorageService;
use App\Service\RotatorService;
use App\Service\SessionManager;

const LOGIN_WINDOW_SECONDS = 900;
const LOGIN_MAX_ATTEMPTS = 6;
const LOGIN_LOCK_SECONDS = 900;
const CACHE_TTL_SERVICES_SECONDS = 300;
const CACHE_TTL_VARIABLES_SECONDS = 120;

function getRequiredEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Missing required configuration: {$name}");
    }

    return $value;
}

function sendApiJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
}

function isValidSecretName(string $name): bool
{
    return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
}

function normalizeLength(?int $length): ?int
{
    if ($length === null) {
        return null;
    }

    if ($length < 8 || $length > 256) {
        throw new InvalidArgumentException('Length must be between 8 and 256');
    }

    return $length;
}

function normalizeEncoding(?string $encoding): ?string
{
    if ($encoding === null || $encoding === '') {
        return null;
    }

    $allowed = ['hex', 'base64', 'alphanumeric'];
    if (!in_array($encoding, $allowed, true)) {
        throw new InvalidArgumentException('Invalid encoding option');
    }

    return $encoding;
}

function cacheKeyServices(string $projectId): string
{
    return 'services:' . $projectId;
}

function cacheKeyVariables(string $projectId, string $environmentId, ?string $serviceId): string
{
    $scope = $serviceId ?: 'global';
    return sprintf('variables:%s:%s:%s', $projectId, $environmentId, $scope);
}

function getServicesCached(RailwayClient $railway, RailwayCacheService $cache, string $projectId, bool $forceRefresh = false): array
{
    $key = cacheKeyServices($projectId);
    if (!$forceRefresh) {
        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $services = $railway->getServices($projectId);
    $cache->put($key, $services, CACHE_TTL_SERVICES_SECONDS);
    return $services;
}

function getVariablesCached(
    RailwayClient $railway,
    RailwayCacheService $cache,
    string $projectId,
    string $environmentId,
    ?string $serviceId,
    bool $forceRefresh = false
): array {
    $key = cacheKeyVariables($projectId, $environmentId, $serviceId);
    if (!$forceRefresh) {
        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $variables = $railway->getVariables($projectId, $environmentId, $serviceId);
    $cache->put($key, $variables, CACHE_TTL_VARIABLES_SECONDS);
    return $variables;
}

function invalidateVariableCache(RailwayCacheService $cache, string $projectId, string $environmentId, ?string $serviceId): void
{
    $cache->delete(cacheKeyVariables($projectId, $environmentId, $serviceId));
}

function buildGroupedServices(array $services, array $groupMap): array
{
    $grouped = [];
    $hasCustomGroups = false;
    foreach ($groupMap as $groupName) {
        if (trim((string)$groupName) !== '') {
            $hasCustomGroups = true;
            break;
        }
    }

    foreach ($services as $service) {
        $serviceId = (string)($service['id'] ?? '');
        $serviceName = (string)($service['name'] ?? '');
        if ($serviceId === '' || $serviceName === '') {
            continue;
        }

        $group = trim((string)($groupMap[$serviceId] ?? ''));
        if ($group === '') {
            $group = $hasCustomGroups ? 'Ungrouped' : 'Services';
        }

        if (!isset($grouped[$group])) {
            $grouped[$group] = [];
        }
        $grouped[$group][] = $service;
    }

    ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($grouped as $group => $items) {
        usort($items, static function (array $a, array $b): int {
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        $grouped[$group] = $items;
    }

    return $grouped;
}

function buildServiceNameMap(array $services): array
{
    $map = [];
    foreach ($services as $service) {
        $id = (string)($service['id'] ?? '');
        $name = (string)($service['name'] ?? '');
        if ($id !== '' && $name !== '') {
            $map[$id] = $name;
        }
    }

    return $map;
}

function getClientIp(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $remote = is_string($remote) ? trim($remote) : '';

    if (!isTrustedProxy($remote)) {
        return $remote !== '' ? $remote : 'unknown';
    }

    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($forwarded) && $forwarded !== '') {
        $parts = explode(',', $forwarded);
        foreach ($parts as $part) {
            $candidate = trim($part);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }

    $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if (is_string($realIp)) {
        $realIp = trim($realIp);
        if (filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }
    }

    return $remote !== '' ? $remote : 'unknown';
}

function isTrustedProxy(string $ip): bool
{
    if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }

    $trusted = trim((string)(getenv('TRUSTED_PROXY_IPS') ?: ''));
    if ($trusted !== '') {
        foreach (explode(',', $trusted) as $candidate) {
            if (trim($candidate) === $ip) {
                return true;
            }
        }
        return false;
    }

    // Fallback: trust private/reserved network hops when explicit list is not configured.
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function loginRateLimiterDbPath(): string
{
    $path = __DIR__ . '/../storage/db/login_rate_limit.sqlite';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $path;
}

function getLoginRateLimiterDb(): SQLite3
{
    static $db = null;
    if ($db instanceof SQLite3) {
        return $db;
    }

    $db = new SQLite3(loginRateLimiterDbPath());
    $db->busyTimeout(5000);
    $db->exec("CREATE TABLE IF NOT EXISTS login_rate_limits (
        ip TEXT PRIMARY KEY,
        failed_count INTEGER NOT NULL DEFAULT 0,
        first_failed_at INTEGER,
        last_failed_at INTEGER,
        lock_until INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_login_rate_lock ON login_rate_limits(lock_until)");

    return $db;
}

function loginIsAllowed(string $ip): array
{
    $db = getLoginRateLimiterDb();
    $now = time();

    $stmt = $db->prepare("SELECT failed_count, first_failed_at, lock_until FROM login_rate_limits WHERE ip = :ip");
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $res = $stmt->execute();
    $entry = $res->fetchArray(SQLITE3_ASSOC) ?: null;

    if (!$entry) {
        return ['allowed' => true, 'retry_after' => 0];
    }

    $lockUntil = (int)($entry['lock_until'] ?? 0);
    if ($lockUntil > $now) {
        return ['allowed' => false, 'retry_after' => $lockUntil - $now];
    }

    $firstFailedAt = (int)($entry['first_failed_at'] ?? 0);
    if ($firstFailedAt > 0 && ($now - $firstFailedAt) > LOGIN_WINDOW_SECONDS) {
        $reset = $db->prepare("UPDATE login_rate_limits
            SET failed_count = 0, first_failed_at = NULL, last_failed_at = NULL, lock_until = 0
            WHERE ip = :ip");
        $reset->bindValue(':ip', $ip, SQLITE3_TEXT);
        $reset->execute();
        return ['allowed' => true, 'retry_after' => 0];
    }

    $failedCount = (int)($entry['failed_count'] ?? 0);
    if ($failedCount >= LOGIN_MAX_ATTEMPTS) {
        $newLock = $now + LOGIN_LOCK_SECONDS;
        $lockStmt = $db->prepare("UPDATE login_rate_limits SET lock_until = :lock_until WHERE ip = :ip");
        $lockStmt->bindValue(':lock_until', $newLock, SQLITE3_INTEGER);
        $lockStmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $lockStmt->execute();
        return ['allowed' => false, 'retry_after' => LOGIN_LOCK_SECONDS];
    }

    return ['allowed' => true, 'retry_after' => 0];
}

function upsertLoginFailure(string $ip, int $now): void
{
    $db = getLoginRateLimiterDb();

    $stmt = $db->prepare("SELECT failed_count, first_failed_at FROM login_rate_limits WHERE ip = :ip");
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $res = $stmt->execute();
    $entry = $res->fetchArray(SQLITE3_ASSOC) ?: null;

    $failedCount = 1;
    $firstFailedAt = $now;
    if ($entry) {
        $existingFirst = (int)($entry['first_failed_at'] ?? 0);
        $existingCount = (int)($entry['failed_count'] ?? 0);

        if ($existingFirst > 0 && ($now - $existingFirst) <= LOGIN_WINDOW_SECONDS) {
            $failedCount = $existingCount + 1;
            $firstFailedAt = $existingFirst;
        }
    }

    $lockUntil = 0;
    if ($failedCount >= LOGIN_MAX_ATTEMPTS) {
        $lockUntil = $now + LOGIN_LOCK_SECONDS;
    }

    $upsert = $db->prepare("INSERT INTO login_rate_limits (ip, failed_count, first_failed_at, last_failed_at, lock_until)
        VALUES (:ip, :failed_count, :first_failed_at, :last_failed_at, :lock_until)
        ON CONFLICT(ip) DO UPDATE SET
            failed_count = excluded.failed_count,
            first_failed_at = excluded.first_failed_at,
            last_failed_at = excluded.last_failed_at,
            lock_until = excluded.lock_until");
    $upsert->bindValue(':ip', $ip, SQLITE3_TEXT);
    $upsert->bindValue(':failed_count', $failedCount, SQLITE3_INTEGER);
    $upsert->bindValue(':first_failed_at', $firstFailedAt, SQLITE3_INTEGER);
    $upsert->bindValue(':last_failed_at', $now, SQLITE3_INTEGER);
    $upsert->bindValue(':lock_until', $lockUntil, SQLITE3_INTEGER);
    $upsert->execute();

    $cleanupBefore = $now - (LOGIN_WINDOW_SECONDS * 2);
    $cleanup = $db->prepare("DELETE FROM login_rate_limits WHERE lock_until < :now AND (last_failed_at IS NULL OR last_failed_at < :cleanup_before)");
    $cleanup->bindValue(':now', $now, SQLITE3_INTEGER);
    $cleanup->bindValue(':cleanup_before', $cleanupBefore, SQLITE3_INTEGER);
    $cleanup->execute();
}

function clearFailedLogin(string $ip): void
{
    $db = getLoginRateLimiterDb();
    $stmt = $db->prepare("DELETE FROM login_rate_limits WHERE ip = :ip");
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->execute();
}

try {
    // Load Env (preferring standard Railway-injected variables)
    $masterKey = getRequiredEnv('MASTER_KEY');
    $adminKey = getRequiredEnv('ADMIN_KEY');
    $sessionSecret = getRequiredEnv('SESSION_SECRET');
    $railwayToken = getRequiredEnv('RAILWAY_TOKEN');

    // These are injected automatically by Railway
    $projectId = getenv('RAILWAY_PROJECT_ID') ?: getenv('PROJECT_ID');
    $environmentId = getenv('RAILWAY_ENVIRONMENT_ID') ?: getenv('ENVIRONMENT_ID');
    if ($projectId === false || trim((string)$projectId) === '') {
        throw new RuntimeException('Missing required configuration: RAILWAY_PROJECT_ID or PROJECT_ID');
    }
    if ($environmentId === false || trim((string)$environmentId) === '') {
        throw new RuntimeException('Missing required configuration: RAILWAY_ENVIRONMENT_ID or ENVIRONMENT_ID');
    }

    $dbPath = __DIR__ . '/../storage/db/secrets.sqlite';
    $cacheDbPath = __DIR__ . '/../storage/db/railway_cache.sqlite';

    $strictCookieMode = trim((string)(getenv('RAILWAY_ENVIRONMENT_NAME') ?: '')) !== '';
    $session = new SessionManager($sessionSecret, $adminKey, $strictCookieMode);
    $railway = new RailwayClient($railwayToken);
    $cache = new RailwayCacheService($cacheDbPath);
    $storage = new StorageService($dbPath, $masterKey);
    $rotator = new RotatorService($railway, $storage);
} catch (\Throwable $e) {
    error_log('Startup configuration error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Server configuration error. Check required environment variables.";
    exit;
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self' https://unpkg.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");

// Basic Routing
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Auth Logic
if ($path === '/login' && $method === 'POST') {
    $ip = getClientIp();
    $rate = loginIsAllowed($ip);
    if (!$rate['allowed']) {
        $wait = max(1, (int)$rate['retry_after']);
        $error = "Too many attempts. Try again in {$wait}s.";
        include __DIR__ . '/../src/Views/login.php';
        exit;
    }

    $key = $_POST['key'] ?? '';
    if ($session->login($key)) {
        clearFailedLogin($ip);
        header('Location: /');
        exit;
    }
    upsertLoginFailure($ip, time());
    $error = "Invalid Admin Key";
}

if ($path === '/logout') {
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo 'Method Not Allowed';
        exit;
    }

    $csrf = $_POST['csrf_token'] ?? null;
    if ($session->isAuthenticated() && !$session->validateCsrfToken($csrf)) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    $session->logout();
    header('Location: /');
    exit;
}

// Protected Routes
if (!$session->isAuthenticated() && $path !== '/login') {
    include __DIR__ . '/../src/Views/login.php';
    exit;
}

$csrfToken = $session->getCsrfToken();
if (!is_string($csrfToken) || $csrfToken === '') {
    $session->logout();
    include __DIR__ . '/../src/Views/login.php';
    exit;
}

// API Endpoints
if ($path === '/api/manage' && $method === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $name = $_POST['name'] ?? '';
    $serviceId = $_POST['serviceId'] ?? null;
    
    try {
        if (!$session->validateCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new InvalidArgumentException('Invalid CSRF token');
        }

        if (!isValidSecretName($name)) {
            throw new InvalidArgumentException('Invalid secret name');
        }

        if ($action === 'delete') {
            $storage->deleteConfig($name, $serviceId);
        } else {
            $length = normalizeLength(isset($_POST['length']) ? (int)$_POST['length'] : null);
            $encoding = normalizeEncoding($_POST['encoding'] ?? null);
            $interval = max(0, (int)($_POST['interval'] ?? 0));
            $storage->saveConfig($name, $serviceId, [
                'length' => $length ?? 32,
                'encoding' => $encoding ?? 'hex',
                'interval_days' => $interval
            ]);
        }
        sendApiJson(200, ['success' => true]);
    } catch (\InvalidArgumentException $e) {
        sendApiJson(400, ['success' => false, 'error' => $e->getMessage()]);
    } catch (\Exception $e) {
        error_log('Manage API error: ' . $e->getMessage());
        sendApiJson(500, ['success' => false, 'error' => 'Internal server error']);
    }
    exit;
}

if ($path === '/api/rotate' && $method === 'POST') {
    $keyName = $_POST['key'] ?? '';
    $serviceId = $_POST['serviceId'] ?: null;
    $manualValue = isset($_POST['manualValue']) ? trim((string)$_POST['manualValue']) : null;
    $length = isset($_POST['length']) ? (int)$_POST['length'] : null;
    $encoding = $_POST['encoding'] ?? null;
    
    try {
        if (!$session->validateCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new InvalidArgumentException('Invalid CSRF token');
        }

        if (!isValidSecretName($keyName)) {
            throw new InvalidArgumentException('Invalid secret name');
        }

        $length = normalizeLength($length);
        $encoding = normalizeEncoding($encoding);
        if ($manualValue === '') {
            $manualValue = null;
        }
        if ($manualValue !== null && strlen($manualValue) > 4096) {
            throw new InvalidArgumentException('Manual value is too large');
        }

        if ($rotator->rotate($keyName, $projectId, $environmentId, $serviceId, $manualValue, $length, $encoding)) {
            invalidateVariableCache($cache, $projectId, $environmentId, $serviceId);
            sendApiJson(200, ['success' => true]);
        } else {
            sendApiJson(500, ['success' => false, 'error' => 'Rotation failed']);
        }
    } catch (\InvalidArgumentException $e) {
        sendApiJson(400, ['success' => false, 'error' => $e->getMessage()]);
    } catch (\Exception $e) {
        error_log('Rotate API error: ' . $e->getMessage());
        sendApiJson(500, ['success' => false, 'error' => 'Internal server error']);
    }
    exit;
}

// HTMX API Endpoints
if ($path === '/api/config-form' && $method === 'GET') {
    $secretName = $_GET['name'] ?? '';
    $serviceId = $_GET['serviceId'] ?? null;
    
    if (!isValidSecretName($secretName)) {
        http_response_code(400);
        echo 'Invalid secret name';
        exit;
    }

    try {
        $managed = $storage->getManagedSecrets();
        $keyId = ($serviceId ?: 'global') . ':' . $secretName;
        $config = $managed[$keyId] ?? null;
        
        include __DIR__ . '/../src/Views/components/config-modal-form.php';
    } catch (\Exception $e) {
        error_log('Config form error: ' . $e->getMessage());
        http_response_code(500);
        echo 'Internal server error';
    }
    exit;
}

if ($path === '/api/config' && $method === 'POST') {
    $name = $_POST['name'] ?? '';
    $serviceId = $_POST['serviceId'] ?? null;
    $mode = $_POST['mode'] ?? 'save_and_rotate';
    
    try {
        if (!$session->validateCsrfToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            exit;
        }

        if (!isValidSecretName($name)) {
            http_response_code(400);
            echo 'Invalid secret name';
            exit;
        }

        if (!in_array($mode, ['rotate_only', 'save_only', 'save_and_rotate'], true)) {
            throw new InvalidArgumentException('Invalid operation mode');
        }

        $length = normalizeLength(isset($_POST['length']) ? (int)$_POST['length'] : null);
        $encoding = normalizeEncoding($_POST['encoding'] ?? null);
        $interval = max(0, (int)($_POST['interval'] ?? 0));

        if ($mode === 'save_only' || $mode === 'save_and_rotate') {
            $storage->saveConfig($name, $serviceId, [
                'length' => $length ?? 32,
                'encoding' => $encoding ?? 'hex',
                'interval_days' => $interval
            ]);
        }

        $manualValue = isset($_POST['manual_value']) ? trim((string)$_POST['manual_value']) : null;
        if ($manualValue === '') {
            $manualValue = null;
        }

        if ($manualValue !== null && strlen($manualValue) > 4096) {
            throw new InvalidArgumentException('Manual value is too large');
        }

        if ($mode === 'rotate_only' || $mode === 'save_and_rotate') {
            $rotator->rotate($name, $projectId, $environmentId, $serviceId, $manualValue, $length, $encoding);
            invalidateVariableCache($cache, $projectId, $environmentId, $serviceId);
        }

        $toastMessage = 'Updated successfully';
        if ($mode === 'save_only') {
            $toastMessage = 'Config saved';
        } elseif ($mode === 'rotate_only') {
            $toastMessage = 'Secret rotated';
        } elseif ($mode === 'save_and_rotate') {
            $toastMessage = 'Config saved and secret rotated';
        }
        header('HX-Trigger: ' . json_encode(['rotatorToast' => ['message' => $toastMessage, 'type' => 'success']]));

        // Return updated table body
        $variables = getVariablesCached($railway, $cache, $projectId, $environmentId, $serviceId, false);
        $managed = $storage->getManagedSecrets();
        
        ob_start();
        include __DIR__ . '/../src/Views/components/secrets-table-body.php';
        echo ob_get_clean();
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['rotatorToast' => ['message' => $e->getMessage(), 'type' => 'error']]));
        echo htmlspecialchars($e->getMessage());
    } catch (\Exception $e) {
        error_log('Config API error: ' . $e->getMessage());
        http_response_code(500);
        header('HX-Trigger: ' . json_encode(['rotatorToast' => ['message' => 'Internal server error', 'type' => 'error']]));
        echo 'Internal server error';
    }
    exit;
}

if ($path === '/api/config' && $method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $data);
    $name = $_GET['name'] ?? $data['name'] ?? '';
    $serviceId = $_GET['serviceId'] ?? $data['serviceId'] ?? null;
    $csrf = $_GET['csrf_token'] ?? $data['csrf_token'] ?? null;
    
    try {
        if (!$session->validateCsrfToken($csrf)) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            exit;
        }

        if (!isValidSecretName($name)) {
            http_response_code(400);
            echo 'Invalid secret name';
            exit;
        }

        $storage->deleteConfig($name, $serviceId);
        header('HX-Trigger: ' . json_encode(['rotatorToast' => ['message' => 'Config removed', 'type' => 'success']]));

        // Return updated table body
        $variables = getVariablesCached($railway, $cache, $projectId, $environmentId, $serviceId, false);
        $managed = $storage->getManagedSecrets();
        
        ob_start();
        include __DIR__ . '/../src/Views/components/secrets-table-body.php';
        echo ob_get_clean();
    } catch (\Exception $e) {
        error_log('Delete config error: ' . $e->getMessage());
        http_response_code(500);
        echo 'Internal server error';
    }
    exit;
}

if ($path === '/api/secrets-table' && $method === 'GET') {
    $serviceId = $_GET['serviceId'] ?? null;
    $forceRefresh = ($_GET['refresh'] ?? '0') === '1';
    
    try {
        $variables = getVariablesCached($railway, $cache, $projectId, $environmentId, $serviceId, $forceRefresh);
        $managed = $storage->getManagedSecrets();
        
        ob_start();
        include __DIR__ . '/../src/Views/components/secrets-table-body.php';
        echo ob_get_clean();
    } catch (\Exception $e) {
        error_log('Table refresh error: ' . $e->getMessage());
        http_response_code(500);
        echo 'Internal server error';
    }
    exit;
}

if ($path === '/api/rotation-history' && $method === 'GET') {
    $serviceId = $_GET['serviceId'] ?? null;
    if ($serviceId === '') {
        $serviceId = null;
    }

    try {
        $services = getServicesCached($railway, $cache, $projectId, false);
        $serviceNameMap = buildServiceNameMap($services);
        $recentHistory = $storage->getRecentHistory($serviceId, 30);

        include __DIR__ . '/../src/Views/components/rotation-history-body.php';
    } catch (\Exception $e) {
        error_log('Rotation history API error: ' . $e->getMessage());
        http_response_code(500);
        echo 'Internal server error';
    }
    exit;
}

if ($path === '/api/rotation-history-detail' && $method === 'GET') {
    $historyId = (int)($_GET['id'] ?? 0);

    try {
        if ($historyId <= 0) {
            throw new InvalidArgumentException('Invalid history id');
        }

        $detail = $storage->getHistoryDetailById($historyId);
        if ($detail === null) {
            sendApiJson(404, ['success' => false, 'error' => 'History entry not found']);
        }

        $serviceId = (string)($detail['service_id'] ?? '');
        $serviceLabel = 'Global Variables';
        if ($serviceId !== '') {
            $services = getServicesCached($railway, $cache, $projectId, false);
            $serviceNameMap = buildServiceNameMap($services);
            $serviceLabel = $serviceNameMap[$serviceId] ?? $serviceId;
        }

        // If new_value is null (latest rotation row), fetch the live current value from Railway
        $newValue = $detail['new_value'];
        $newValueIsLive = false;
        if ($newValue === null) {
            try {
                $currentVars = $railway->getVariables($projectId, $environmentId, $serviceId !== '' ? $serviceId : null);
                $secretName = (string)$detail['secret_name'];
                if (isset($currentVars[$secretName])) {
                    $newValue = $currentVars[$secretName];
                    $newValueIsLive = true;
                }
            } catch (\Throwable $e) {
                // Leave new_value null — not a fatal error
            }
        }

        sendApiJson(200, [
            'success' => true,
            'data' => [
                'id' => (int)$detail['id'],
                'secret_name' => (string)$detail['secret_name'],
                'service' => $serviceLabel,
                'rotated_at' => (string)$detail['rotated_at'],
                'trigger_type' => (string)($detail['trigger_type'] ?? 'manual'),
                'old_value' => $detail['old_value'],
                'new_value' => $newValue,
                'new_value_is_live' => $newValueIsLive,
            ],
        ]);
    } catch (\InvalidArgumentException $e) {
        sendApiJson(400, ['success' => false, 'error' => $e->getMessage()]);
    } catch (\Exception $e) {
        error_log('Rotation history detail API error: ' . $e->getMessage());
        sendApiJson(500, ['success' => false, 'error' => 'Internal server error']);
    }
    exit;
}

if ($path === '/api/cache/refresh' && $method === 'POST') {
    $scope = $_POST['scope'] ?? 'all';
    $serviceId = $_POST['serviceId'] ?? null;

    try {
        if (!$session->validateCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new InvalidArgumentException('Invalid CSRF token');
        }

        if (!in_array($scope, ['services', 'variables', 'all'], true)) {
            throw new InvalidArgumentException('Invalid cache scope');
        }

        if ($scope === 'services' || $scope === 'all') {
            $cache->delete(cacheKeyServices($projectId));
            getServicesCached($railway, $cache, $projectId, true);
        }

        if ($scope === 'variables' || $scope === 'all') {
            if ($scope === 'all') {
                $cache->deletePrefix(sprintf('variables:%s:%s:', $projectId, $environmentId));
            } else {
                invalidateVariableCache($cache, $projectId, $environmentId, $serviceId ?: null);
            }
            getVariablesCached($railway, $cache, $projectId, $environmentId, $serviceId ?: null, true);
        }

        sendApiJson(200, ['success' => true, 'message' => 'Cache refreshed']);
    } catch (\InvalidArgumentException $e) {
        sendApiJson(400, ['success' => false, 'error' => $e->getMessage()]);
    } catch (\Exception $e) {
        error_log('Cache refresh API error: ' . $e->getMessage());
        sendApiJson(500, ['success' => false, 'error' => 'Internal server error']);
    }
    exit;
}

if ($path === '/api/service-group' && $method === 'POST') {
    try {
        if (!$session->validateCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new InvalidArgumentException('Invalid CSRF token');
        }

        $serviceId = trim((string)($_POST['serviceId'] ?? ''));
        $groupName = isset($_POST['groupName']) ? trim((string)$_POST['groupName']) : null;
        if ($serviceId === '') {
            throw new InvalidArgumentException('Invalid service id');
        }

        $storage->setServiceGroup($serviceId, $groupName);
        sendApiJson(200, ['success' => true]);
    } catch (\InvalidArgumentException $e) {
        sendApiJson(400, ['success' => false, 'error' => $e->getMessage()]);
    } catch (\Exception $e) {
        error_log('Service group API error: ' . $e->getMessage());
        sendApiJson(500, ['success' => false, 'error' => 'Internal server error']);
    }
    exit;
}

if ($path === '/api/service-group/bulk' && $method === 'POST') {
    try {
        if (!$session->validateCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new InvalidArgumentException('Invalid CSRF token');
        }

        $groupName = trim((string)($_POST['groupName'] ?? ''));
        if ($groupName === '') {
            throw new InvalidArgumentException('Group name is required');
        }

        $serviceIds = $_POST['serviceIds'] ?? [];
        if (!is_array($serviceIds)) {
            $serviceIds = [$serviceIds];
        }

        $normalizedServiceIds = [];
        foreach ($serviceIds as $serviceId) {
            $serviceId = trim((string)$serviceId);
            if ($serviceId !== '') {
                $normalizedServiceIds[] = $serviceId;
            }
        }

        if (empty($normalizedServiceIds)) {
            throw new InvalidArgumentException('Select at least one service');
        }

        foreach ($normalizedServiceIds as $serviceId) {
            $storage->setServiceGroup($serviceId, $groupName);
        }

        sendApiJson(200, ['success' => true, 'updated' => count($normalizedServiceIds)]);
    } catch (\InvalidArgumentException $e) {
        sendApiJson(400, ['success' => false, 'error' => $e->getMessage()]);
    } catch (\Exception $e) {
        error_log('Bulk service group API error: ' . $e->getMessage());
        sendApiJson(500, ['success' => false, 'error' => 'Internal server error']);
    }
    exit;
}

// Docs
if ($path === '/docs') {
    $services = [];
    $groupedServices = [];
    $timeConfig = getRotationTimeConfig();
    try {
        $services = getServicesCached($railway, $cache, $projectId, false);
        $storage->syncServiceNames($services);
        $groupedServices = buildGroupedServices($services, $storage->getServiceGroupMap());
    } catch (\Exception $e) {
        error_log('Docs Railway services error: ' . $e->getMessage());
    }

    include __DIR__ . '/../src/Views/docs.php';
    exit;
}

// Render Dashboard
if ($path === '/' || $path === '') {
    $serviceId = $_GET['serviceId'] ?? null;
    $section = $_GET['section'] ?? ($serviceId ? 'secrets' : 'overview');
    if (!in_array($section, ['overview', 'secrets', 'history'], true)) {
        $section = $serviceId ? 'secrets' : 'overview';
    }
    $viewTitle = $section === 'overview' ? 'Dashboard Overview' : 'Global Variables';
    $isHtmx = isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] === 'true';
    $forceRefresh = ($_GET['refresh'] ?? '0') === '1';
    $timeConfig = getRotationTimeConfig();
    
    try {
        $services = getServicesCached($railway, $cache, $projectId, $forceRefresh);
        $storage->syncServiceNames($services);
        $groupedServices = buildGroupedServices($services, $storage->getServiceGroupMap());
        $serviceNameMap = buildServiceNameMap($services);
        
        if ($serviceId) {
            foreach ($services as $s) {
                if ($s['id'] === $serviceId) {
                    $viewTitle = $s['name'];
                    break;
                }
            }
        }

        if ($section === 'history') {
            $viewTitle = $serviceId ? ($viewTitle . ' History') : 'Rotation History';
        } elseif ($section === 'overview') {
            $viewTitle = 'Dashboard Overview';
        }

        $variables = getVariablesCached($railway, $cache, $projectId, $environmentId, $serviceId, $forceRefresh);
        $variablesCacheInfo = $cache->getInfo(cacheKeyVariables($projectId, $environmentId, $serviceId));
        $cacheFetchedAt = (int)($variablesCacheInfo['fetched_at'] ?? 0);
        $recentHistory = $storage->getRecentHistory($serviceId ?: null, 30);
        $managed = $storage->getManagedSecrets();
        $serviceCount = $storage->getServiceCount();
        if ($isHtmx) {
            include __DIR__ . '/../src/Views/components/dashboard-main.php';
        } else {
            include __DIR__ . '/../src/Views/dashboard.php';
        }
    } catch (\Exception $e) {
        error_log('Dashboard Railway error: ' . $e->getMessage());
        $error = "Unable to load Railway data right now. Please retry.";
        $variables = [];
        $services = [];
        $groupedServices = [];
        $serviceNameMap = [];
        $managed = [];
        $cacheFetchedAt = 0;
        $recentHistory = [];
        $serviceCount = $storage->getServiceCount();
        $timeConfig = getRotationTimeConfig();
        if ($isHtmx) {
            include __DIR__ . '/../src/Views/components/dashboard-main.php';
        } else {
            include __DIR__ . '/../src/Views/dashboard.php';
        }
    }
    exit;
}
