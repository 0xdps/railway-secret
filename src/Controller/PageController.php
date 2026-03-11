<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ProjectConfig;
use App\Helpers;
use App\Service\RailwayCacheService;
use App\Service\RailwayClient;
use App\Service\SessionManager;
use App\Service\StorageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class PageController
{
    public function __construct(
        private readonly RailwayClient       $railway,
        private readonly RailwayCacheService $cache,
        private readonly StorageService      $storage,
        private readonly SessionManager      $session,
        private readonly ProjectConfig       $projectConfig,
        private readonly LoggerInterface     $logger,
    ) {}

    // ── Pages ────────────────────────────────────────────────────────────────

    public function dashboard(Request $request, Response $response): Response
    {
        $csrfToken = (string)$request->getAttribute('csrfToken', '');
        $params    = $request->getQueryParams();
        $serviceId = ($params['serviceId'] ?? '') ?: null;
        $section   = $params['section'] ?? ($serviceId ? 'secrets' : 'overview');

        if (!in_array($section, ['overview', 'secrets', 'history'], true)) {
            $section = $serviceId ? 'secrets' : 'overview';
        }

        $viewTitle    = $section === 'overview' ? 'Dashboard Overview' : 'Global Variables';
        $isHtmx       = $this->isHtmxRequest($request);
        $forceRefresh = ($params['refresh'] ?? '0') === '1';

        try {
            $services = Helpers::getServicesCached($this->railway, $this->cache, $this->projectConfig->projectId, $forceRefresh);
            $this->storage->syncServiceNames($services);
            $groupedServices = Helpers::buildGroupedServices($services, $this->storage->getServiceGroupMap());
            $serviceNameMap  = Helpers::buildServiceNameMap($services);

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

            $variables          = Helpers::getVariablesCached($this->railway, $this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId, $forceRefresh);
            $variablesCacheInfo = $this->cache->getInfo(Helpers::cacheKeyVariables($this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId));
            $cacheFetchedAt     = (int)($variablesCacheInfo['fetched_at'] ?? 0);
            $recentHistory      = $this->storage->getRecentHistory($serviceId, 30);
            $managed            = $this->storage->getManagedSecrets();
            $serviceCount       = $this->storage->getServiceCount();
            $rotations24h       = $this->storage->getRotationsLast24h($serviceId);
        } catch (\Exception $e) {
            $this->logger->error('Dashboard Railway error', ['error' => $e->getMessage()]);
            $error           = 'Unable to load Railway data right now. Please retry.';
            $variables       = [];
            $services        = [];
            $groupedServices = [];
            $serviceNameMap  = [];
            $managed         = [];
            $cacheFetchedAt  = 0;
            $recentHistory   = [];
            $serviceCount    = $this->storage->getServiceCount();
            $rotations24h    = 0;
        }

        $vars = compact(
            'csrfToken', 'serviceId', 'section', 'viewTitle',
            'services', 'groupedServices', 'serviceNameMap',
            'variables', 'cacheFetchedAt',
            'recentHistory', 'managed',
            'serviceCount', 'rotations24h',
        );
        if (isset($error)) {
            $vars['error'] = $error;
        }

        return $isHtmx
            ? $this->renderComponent($response, 'dashboard-main.php', $vars)
            : $this->renderView($response, 'dashboard.php', $vars);
    }

    public function managed(Request $request, Response $response): Response
    {
        $csrfToken = (string)$request->getAttribute('csrfToken', '');
        $isHtmx    = $this->isHtmxRequest($request);
        $viewTitle  = 'Managed Secrets';
        $section    = 'managed';
        $serviceId  = null;

        try {
            $services        = Helpers::getServicesCached($this->railway, $this->cache, $this->projectConfig->projectId, false);
            $this->storage->syncServiceNames($services);
            $groupedServices = Helpers::buildGroupedServices($services, $this->storage->getServiceGroupMap());
            $serviceNameMap  = Helpers::buildServiceNameMap($services);
            $allManaged      = $this->storage->getAllManagedWithServiceNames();
        } catch (\Exception $e) {
            $this->logger->error('Managed page error', ['error' => $e->getMessage()]);
            $groupedServices = $groupedServices ?? [];
            $serviceNameMap  = $serviceNameMap  ?? [];
            $allManaged      = [];
        }

        $vars = compact('csrfToken', 'serviceId', 'section', 'viewTitle', 'groupedServices', 'serviceNameMap', 'allManaged');

        return $isHtmx
            ? $this->renderComponent($response, 'managed-main.php', $vars)
            : $this->renderView($response, 'managed.php', $vars);
    }

    public function docs(Request $request, Response $response): Response
    {
        $csrfToken = (string)$request->getAttribute('csrfToken', '');
        $section   = 'docs';
        $viewTitle  = 'Documentation';

        try {
            $services        = Helpers::getServicesCached($this->railway, $this->cache, $this->projectConfig->projectId, false);
            $this->storage->syncServiceNames($services);
            $groupedServices = Helpers::buildGroupedServices($services, $this->storage->getServiceGroupMap());
        } catch (\Exception $e) {
            $this->logger->error('Docs Railway services error', ['error' => $e->getMessage()]);
            $services        = [];
            $groupedServices = [];
        }

        return $this->renderView($response, 'docs.php', compact('csrfToken', 'section', 'viewTitle', 'services', 'groupedServices'));
    }

    public function about(Request $request, Response $response): Response
    {
        $csrfToken = (string)$request->getAttribute('csrfToken', '');
        $isHtmx    = $this->isHtmxRequest($request);
        $section   = 'about';
        $viewTitle  = 'About';

        try {
            $services        = Helpers::getServicesCached($this->railway, $this->cache, $this->projectConfig->projectId, false);
            $this->storage->syncServiceNames($services);
            $groupedServices = Helpers::buildGroupedServices($services, $this->storage->getServiceGroupMap());
        } catch (\Exception $e) {
            $this->logger->error('About Railway services error', ['error' => $e->getMessage()]);
            $services        = [];
            $groupedServices = [];
        }

        $vars = compact('csrfToken', 'section', 'viewTitle', 'services', 'groupedServices');

        return $isHtmx
            ? $this->renderComponent($response, 'about-content.php', $vars)
            : $this->renderView($response, 'about.php', $vars);
    }

    // ── Shared render helpers ────────────────────────────────────────────────

    private function renderView(Response $response, string $template, array $vars = []): Response
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        include dirname(__DIR__) . '/Views/' . $template;
        $response->getBody()->write((string)ob_get_clean());
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function renderComponent(Response $response, string $component, array $vars = []): Response
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        include dirname(__DIR__) . '/Views/components/' . $component;
        $response->getBody()->write((string)ob_get_clean());
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function isHtmxRequest(Request $request): bool
    {
        return $request->getHeaderLine('HX-Request') === 'true';
    }
}
