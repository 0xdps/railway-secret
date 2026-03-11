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

    RailwayCacheService::class => static function (): RailwayCacheService {
        $masterKey    = Helpers::requireEnv('MASTER_KEY');
        $cacheDbPath  = dirname(__DIR__) . '/storage/db/railway_cache.sqlite';
        return new RailwayCacheService($cacheDbPath, $masterKey);
    },

    StorageService::class => static function (): StorageService {
        $masterKey = Helpers::requireEnv('MASTER_KEY');
        $dbPath    = dirname(__DIR__) . '/storage/db/secrets.sqlite';
        return new StorageService($dbPath, $masterKey);
    },

    RotatorService::class => static function (RailwayClient $railway, StorageService $storage): RotatorService {
        return new RotatorService($railway, $storage);
    },

    LoginRateLimiter::class => static fn(): LoginRateLimiter => new LoginRateLimiter(),
];
