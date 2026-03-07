<?php

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $assetPath = __DIR__ . $requestPath;
    if (is_file($assetPath)) {
        return false;
    }
}

require_once __DIR__ . '/../bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

use App\Service\RailwayClient;
use App\Service\StorageService;
use App\Service\RotatorService;
use App\Service\SessionManager;

const LOGIN_WINDOW_SECONDS = 900;
const LOGIN_MAX_ATTEMPTS = 6;
const LOGIN_LOCK_SECONDS = 900;

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
    $projectId = getenv('RAILWAY_RAILWAY_PROJECT_ID') ?: getenv('RAILWAY_PROJECT_ID');
    $environmentId = getenv('RAILWAY_RAILWAY_ENVIRONMENT_ID') ?: getenv('RAILWAY_ENVIRONMENT_ID');
    if ($projectId === false || trim((string)$projectId) === '') {
        throw new RuntimeException('Missing required configuration: RAILWAY_RAILWAY_PROJECT_ID or RAILWAY_PROJECT_ID');
    }
    if ($environmentId === false || trim((string)$environmentId) === '') {
        throw new RuntimeException('Missing required configuration: RAILWAY_RAILWAY_ENVIRONMENT_ID or RAILWAY_ENVIRONMENT_ID');
    }

    $dbPath = __DIR__ . '/../storage/db/secrets.sqlite';

    $strictCookieMode = trim((string)(getenv('RAILWAY_ENVIRONMENT_NAME') ?: '')) !== '';
    $session = new SessionManager($sessionSecret, $adminKey, $strictCookieMode);
    $railway = new RailwayClient($railwayToken);
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

// Docs
if ($path === '/docs') {
    include __DIR__ . '/../src/Views/docs.php';
    exit;
}

// Render Dashboard
if ($path === '/' || $path === '') {
    $serviceId = $_GET['serviceId'] ?? null;
    $viewTitle = 'Global Variables';
    
    try {
        $services = $railway->getServices($projectId);
        
        if ($serviceId) {
            foreach ($services as $s) {
                if ($s['id'] === $serviceId) {
                    $viewTitle = $s['name'];
                    break;
                }
            }
        }

        $variables = $railway->getVariables($projectId, $environmentId, $serviceId);
        $managed = $storage->getManagedSecrets();
        include __DIR__ . '/../src/Views/dashboard.php';
    } catch (\Exception $e) {
        error_log('Dashboard Railway error: ' . $e->getMessage());
        $error = "Unable to load Railway data right now. Please retry.";
        $variables = [];
        $services = [];
        $managed = [];
        include __DIR__ . '/../src/Views/dashboard.php';
    }
    exit;
}
