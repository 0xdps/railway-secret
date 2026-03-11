<?php

declare(strict_types=1);

// -- PHP CLI built-in server: serve static files directly ---------------------
if (PHP_SAPI === 'cli-server') {
    $earlyPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (is_file(__DIR__ . $earlyPath)) {
        return false;
    }
}

// -- Fast health check (no bootstrap needed) ----------------------------------
$earlyPath   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$earlyMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (($earlyPath === '/health' || $earlyPath === '/healthz')
    && ($earlyMethod === 'GET' || $earlyMethod === 'HEAD')
) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'status' => 'healthy', 'timestamp' => gmdate('c')]);
    exit;
}

// -- Bootstrap ----------------------------------------------------------------
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use App\Controller\ApiController;
use App\Controller\AuthController;
use App\Controller\PageController;
use App\Middleware\AuthMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use DI\Bridge\Slim\Bridge;
use DI\ContainerBuilder;
use Slim\Routing\RouteCollectorProxy;

// -- DI Container -------------------------------------------------------------
try {
    $builder = new ContainerBuilder();
    $builder->addDefinitions(__DIR__ . '/../config/container.php');
    $container = $builder->build();
    $app       = Bridge::create($container);
} catch (\Throwable $e) {
    error_log('Startup configuration error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Server configuration error. Check required environment variables.';
    exit;
}

// -- Middleware (outermost added last = runs first on the way in) --------------
$app->add(SecurityHeadersMiddleware::class);
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, true, true);

// -- Auth routes (no login required) ------------------------------------------
$app->get('/login',  [AuthController::class, 'showLogin']);
$app->post('/login', [AuthController::class, 'login']);
$app->post('/logout', [AuthController::class, 'logout']);

// -- Protected routes (AuthMiddleware applied to the whole group) --------------
$app->group('', function (RouteCollectorProxy $group) {
    // Pages
    $group->get('/',        [PageController::class, 'dashboard']);
    $group->get('/managed', [PageController::class, 'managed']);
    $group->get('/docs',    [PageController::class, 'docs']);
    $group->get('/about',   [PageController::class, 'about']);

    // API endpoints
    $group->post(  '/api/manage',                  [ApiController::class, 'manage']);
    $group->post(  '/api/rollback',                [ApiController::class, 'rollback']);
    $group->post(  '/api/rotate',                  [ApiController::class, 'rotate']);
    $group->post(  '/api/rotate-sync-group',       [ApiController::class, 'rotateSyncGroup']);
    $group->post(  '/api/rotate-all-due',          [ApiController::class, 'rotateAllDue']);
    $group->get(   '/api/secret-value',            [ApiController::class, 'secretValue']);
    $group->post(  '/api/sync-group-config',       [ApiController::class, 'syncGroupConfigSave']);
    $group->delete('/api/sync-group-config',       [ApiController::class, 'syncGroupConfigDelete']);
    $group->get(   '/api/config-form',             [ApiController::class, 'configForm']);
    $group->get(   '/api/rotate-form',             [ApiController::class, 'rotateForm']);
    $group->post(  '/api/config',                  [ApiController::class, 'configSave']);
    $group->delete('/api/config',                  [ApiController::class, 'configDelete']);
    $group->get(   '/api/secrets-table',           [ApiController::class, 'secretsTable']);
    $group->get(   '/api/rotation-history',        [ApiController::class, 'rotationHistory']);
    $group->get(   '/api/rotation-history-detail', [ApiController::class, 'rotationHistoryDetail']);
    $group->post(  '/api/cache/refresh',           [ApiController::class, 'cacheRefresh']);
    $group->post(  '/api/service-group',           [ApiController::class, 'serviceGroup']);
    $group->post(  '/api/service-group/bulk',      [ApiController::class, 'serviceGroupBulk']);
})->add(AuthMiddleware::class);

$app->run();
