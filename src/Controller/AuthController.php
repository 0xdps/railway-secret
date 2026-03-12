<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ProjectConfig;
use App\Helpers;
use App\Service\LoginRateLimiter;
use App\Service\RailwayCacheService;
use App\Service\RailwayClient;
use App\Service\SessionManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class AuthController
{
    public function __construct(
        private readonly SessionManager      $session,
        private readonly LoginRateLimiter    $rateLimiter,
        private readonly RailwayClient       $railway,
        private readonly RailwayCacheService $cache,
        private readonly ProjectConfig       $projectConfig,
        private readonly LoggerInterface     $logger,
    ) {}

    public function showLogin(Request $request, Response $response): Response
    {
        $error = null;
        return $this->loginPage($response, $error);
    }

    public function login(Request $request, Response $response): Response
    {
        $ip   = Helpers::getClientIp();
        $rate = $this->rateLimiter->isAllowed($ip);

        if (!$rate['allowed']) {
            $wait  = max(1, (int)$rate['retry_after']);
            $error = "Too many attempts. Try again in {$wait}s.";
            return $this->loginPage($response, $error);
        }

        $body = (array)($request->getParsedBody() ?? []);
        $key  = $body['key'] ?? '';

        if ($this->session->login($key)) {
            $this->rateLimiter->clearFailure($ip);
            // Warm cache so the dashboard loads instantly.
            try {
                Helpers::getServicesCached($this->railway, $this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, true);
                Helpers::getVariablesCached($this->railway, $this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, null, true);
            } catch (\Exception $e) {
                $this->logger->warning('Cache warm on login failed', ['error' => $e->getMessage()]);
            }
            return $response->withStatus(302)->withHeader('Location', '/');
        }

        $this->rateLimiter->recordFailure($ip, time());
        $error = 'Invalid Admin Key';
        return $this->loginPage($response, $error);
    }

    public function logout(Request $request, Response $response): Response
    {
        $body = (array)($request->getParsedBody() ?? []);
        $csrf = $body['csrf_token'] ?? null;

        if ($this->session->isAuthenticated() && !$this->session->validateCsrfToken($csrf)) {
            return $response
                ->withStatus(403)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $this->session->logout();
        return $response->withStatus(302)->withHeader('Location', '/');
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function loginPage(Response $response, ?string $error): Response
    {
        ob_start();
        include dirname(__DIR__) . '/Views/login.php';
        $response->getBody()->write((string)ob_get_clean());
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
