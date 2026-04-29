<?php

declare(strict_types=1);

use App\Config\ProjectConfig;
use App\Helpers;
use App\Service\LoginRateLimiter;
use App\Service\RailwayCacheService;
use App\Service\RailwayClient;
use App\Service\RotatorService;
use App\Service\SessionManager;
use App\Service\StorageService;
use GuzzleHttp\Client as GuzzleClient;
use Mesahub\DatabaseHandle;
use Mesahub\MesahubClient;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

return [
    // ── Logger ────────────────────────────────────────────────────────────────
    LoggerInterface::class => static function (): LoggerInterface {
        $logger = new Logger('app');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));
        return $logger;
    },

    // ── Mesahub database handle (shared across all services) ─────────────────
    DatabaseHandle::class => static function (): DatabaseHandle {
        $info   = MesahubClient::parseMesahubUrl(Helpers::requireEnv('MESAHUB_URL'));
        $client = new MesahubClient(
            apiKey:      $info['api_key'],
            apiUrl:      $info['api_url'],
            routePrefix: $info['route_prefix'],
        );
        return $client->db($info['db_name']);
    },

    // ── Railway project context ──────────────────────────────────────────────
    ProjectConfig::class => static function (): ProjectConfig {
        $projectId     = getenv('RAILWAY_PROJECT_ID') ?: getenv('PROJECT_ID');
        $environmentId = getenv('RAILWAY_ENVIRONMENT_ID') ?: getenv('ENVIRONMENT_ID');

        if ($projectId === false || trim((string)$projectId) === '') {
            throw new \RuntimeException('Missing required configuration: RAILWAY_PROJECT_ID or PROJECT_ID');
        }
        if ($environmentId === false || trim((string)$environmentId) === '') {
            throw new \RuntimeException('Missing required configuration: RAILWAY_ENVIRONMENT_ID or ENVIRONMENT_ID');
        }

        return new ProjectConfig((string)$projectId, (string)$environmentId);
    },

    // ── Services ─────────────────────────────────────────────────────────────
    SessionManager::class => static function (): SessionManager {
        $sessionSecret    = Helpers::requireEnv('SESSION_SECRET');
        $adminKey         = Helpers::requireEnv('ADMIN_KEY');
        $strictCookieMode = trim((string)(getenv('RAILWAY_ENVIRONMENT_NAME') ?: '')) !== '';
        return new SessionManager($sessionSecret, $adminKey, $strictCookieMode);
    },

    RailwayClient::class => static function (LoggerInterface $logger): RailwayClient {
        return new RailwayClient(
            Helpers::requireEnv('RAILWAY_TOKEN'),
            new GuzzleClient(['timeout' => 30, 'connect_timeout' => 10]),
            $logger
        );
    },

    RailwayCacheService::class => static function (DatabaseHandle $db): RailwayCacheService {
        $masterKey = Helpers::requireEnv('MASTER_KEY');
        return new RailwayCacheService($db, $masterKey);
    },

    StorageService::class => static function (DatabaseHandle $db): StorageService {
        $masterKey = Helpers::requireEnv('MASTER_KEY');
        return new StorageService($db, $masterKey);
    },

    RotatorService::class => static function (RailwayClient $railway, StorageService $storage): RotatorService {
        return new RotatorService($railway, $storage);
    },

    LoginRateLimiter::class => static function (DatabaseHandle $db): LoginRateLimiter {
        return new LoginRateLimiter($db);
    },
];
