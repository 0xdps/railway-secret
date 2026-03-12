<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ProjectConfig;
use App\Helpers;
use App\Service\RailwayCacheService;
use App\Service\RailwayClient;
use App\Service\RotatorService;
use App\Service\SessionManager;
use App\Service\StorageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class ApiController
{
    public function __construct(
        private readonly RailwayClient       $railway,
        private readonly RailwayCacheService $cache,
        private readonly StorageService      $storage,
        private readonly RotatorService      $rotator,
        private readonly SessionManager      $session,
        private readonly ProjectConfig       $projectConfig,
        private readonly LoggerInterface     $logger,
    ) {}

    // ── /api/manage ─────────────────────────────────────────────────────────

    public function manage(Request $request, Response $response): Response
    {
        $body      = (array)($request->getParsedBody() ?? []);
        $action    = $body['action'] ?? 'save';
        $name      = $body['name']      ?? '';
        $serviceId = ($body['serviceId'] ?? '') ?: null;

        try {
            if (!$this->session->validateCsrfToken($body['csrf_token'] ?? null)) {
                throw new \InvalidArgumentException('Invalid CSRF token');
            }
            if (!Helpers::isValidSecretName($name)) {
                throw new \InvalidArgumentException('Invalid secret name');
            }
            if ($action === 'delete') {
                $this->storage->deleteConfig($name, $serviceId);
            } else {
                $length       = Helpers::normalizeLength(isset($body['length']) ? (int)$body['length'] : null);
                $encoding     = Helpers::normalizeEncoding($body['encoding'] ?? null);
                $interval     = max(0, (int)($body['interval'] ?? 0));
                $intervalUnit = Helpers::normalizeUnit($body['interval_unit'] ?? null);
                $this->storage->saveConfig($name, $serviceId, [
                    'length'        => $length ?? 32,
                    'encoding'      => $encoding ?? 'hex',
                    'interval_days' => $interval,
                    'interval_unit' => $intervalUnit,
                ]);
            }
            return Helpers::apiJson($response, 200, ['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return Helpers::apiJson($response, 400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Manage API error', ['error' => $e->getMessage()]);
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Internal server error']);
        }
    }

    // ── /api/rollback ────────────────────────────────────────────────────────

    public function rollback(Request $request, Response $response): Response
    {
        $body      = (array)($request->getParsedBody() ?? []);
        $keyName   = $body['key']       ?? '';
        $serviceId = ($body['serviceId'] ?? '') ?: null;
        $historyId = isset($body['historyId']) ? (int)$body['historyId'] : 0;

        try {
            if (!$this->session->validateCsrfToken($body['csrf_token'] ?? null)) {
                throw new \InvalidArgumentException('Invalid CSRF token');
            }
            if (!Helpers::isValidSecretName($keyName)) {
                throw new \InvalidArgumentException('Invalid secret name');
            }

            $currentVars  = $this->railway->getVariables($this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId);
            $currentValue = $currentVars[$keyName] ?? null;

            if ($historyId > 0) {
                $detail = $this->storage->getHistoryDetailById($historyId);
                if ($detail === null || ($detail['secret_name'] ?? '') !== $keyName) {
                    throw new \InvalidArgumentException('History entry not found');
                }
                $restoredValue = $detail['old_value'] ?? null;
                if ($restoredValue === null || $restoredValue === '') {
                    throw new \RuntimeException('No recoverable value in that history entry');
                }
                $success = $this->railway->upsertVariable($this->projectConfig->projectId, $this->projectConfig->environmentId, $keyName, $restoredValue, $serviceId);
            } else {
                $history = $this->storage->getHistory($keyName, $serviceId);
                if (empty($history)) {
                    throw new \RuntimeException('No history found for this secret');
                }
                $restoredValue = $history[0]['secret_value'];
                $success       = $this->railway->upsertVariable($this->projectConfig->projectId, $this->projectConfig->environmentId, $keyName, $restoredValue, $serviceId);
            }

            if ($success) {
                $this->storage->addHistory($keyName, $currentValue, $serviceId, 'rollback');
                $this->storage->updateLatestHistoryNewValue($keyName, $serviceId, $restoredValue);
                Helpers::invalidateVariableCache($this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId);
                return Helpers::apiJson($response, 200, ['success' => true]);
            }
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Rollback failed']);
        } catch (\InvalidArgumentException $e) {
            return Helpers::apiJson($response, 400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Rollback API error', ['error' => $e->getMessage()]);
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Internal server error']);
        }
    }

    // ── /api/rotate ──────────────────────────────────────────────────────────

    public function rotate(Request $request, Response $response): Response
    {
        $body        = (array)($request->getParsedBody() ?? []);
        $keyName     = $body['key']     ?? '';
        $serviceId   = ($body['serviceId'] ?? '') ?: null;
        $manualValue = isset($body['manualValue']) ? trim((string)$body['manualValue']) : null;
        $length      = isset($body['length']) ? (int)$body['length'] : null;
        $encoding    = $body['encoding'] ?? null;

        try {
            if (!$this->session->validateCsrfToken($body['csrf_token'] ?? null)) {
                throw new \InvalidArgumentException('Invalid CSRF token');
            }
            if (!Helpers::isValidSecretName($keyName)) {
                throw new \InvalidArgumentException('Invalid secret name');
            }
            $length      = Helpers::normalizeLength($length);
            $encoding    = Helpers::normalizeEncoding($encoding);
            $manualValue = ($manualValue === '') ? null : $manualValue;
            if ($manualValue !== null && strlen($manualValue) > 4096) {
                throw new \InvalidArgumentException('Manual value is too large');
            }
            if ($this->rotator->rotate($keyName, $this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId, $manualValue, $length, $encoding)) {
                Helpers::invalidateVariableCache($this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId);
                return Helpers::apiJson($response, 200, ['success' => true]);
            }
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Rotation failed']);
        } catch (\InvalidArgumentException $e) {
            return Helpers::apiJson($response, 400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Rotate API error', ['error' => $e->getMessage()]);
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Internal server error']);
        }
    }

    // ── /api/rotate-sync-group ───────────────────────────────────────────────

    public function rotateSyncGroup(Request $request, Response $response): Response
    {
        $body = (array)($request->getParsedBody() ?? []);

        try {
            if (!$this->session->validateCsrfToken($body['csrf_token'] ?? null)) {
                throw new \InvalidArgumentException('Invalid CSRF token');
            }
            $groupName = Helpers::normalizeSyncGroup($body['groupName'] ?? null);
            if ($groupName === null) {
                throw new \InvalidArgumentException('Sync group is required');
            }
            $members = $this->storage->getSyncGroupMembers($groupName);
            if (empty($members)) {
                throw new \InvalidArgumentException('Sync group has no members');
            }
            if (!$this->rotator->rotateSyncGroup($groupName, $this->projectConfig->projectId, $this->projectConfig->environmentId, null, 'sync-manual')) {
                return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Sync group rotation failed']);
            }
            $scopeSeen = [];
            foreach ($members as $member) {
                $scope = (($member['service_id'] ?? '') !== '' ? (string)$member['service_id'] : '__global__');
                if (isset($scopeSeen[$scope])) {
                    continue;
                }
                $scopeSeen[$scope] = true;
                Helpers::invalidateVariableCache($this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, $scope === '__global__' ? null : $scope);
            }
            return Helpers::apiJson($response, 200, ['success' => true, 'rotated' => count($members), 'group' => $groupName]);
        } catch (\InvalidArgumentException $e) {
            return Helpers::apiJson($response, 400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Rotate sync-group API error', ['error' => $e->getMessage()]);
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Internal server error']);
        }
    }

    // ── /api/rotate-all-due ──────────────────────────────────────────────────

    public function rotateAllDue(Request $request, Response $response): Response
    {
        $body = (array)($request->getParsedBody() ?? []);

        try {
            if (!$this->session->validateCsrfToken($body['csrf_token'] ?? null)) {
                throw new \InvalidArgumentException('Invalid CSRF token');
            }

            $managed    = $this->storage->getManagedSecrets();
            $dueSecrets = [];
            $syncGroups = [];

            foreach ($managed as $config) {
                $interval  = (int)($config['interval_days'] ?? 0);
                $serviceId = ($config['service_id'] ?? '') ?: null;
                $syncGroup = trim((string)($config['sync_group'] ?? ''));

                if ($syncGroup !== '') {
                    if (!isset($syncGroups[$syncGroup])) {
                        $syncGroups[$syncGroup] = ['members' => [], 'due' => false];
                    }
                    $syncGroups[$syncGroup]['members'][] = array_merge($config, ['service_id' => $serviceId]);
                }

                if ($interval === 0) {
                    continue;
                }

                $secret = $config['secret_name'];
                $isDue  = !empty($config['next_rotation_at'])
                    && strtotime((string)$config['next_rotation_at']) <= time();

                if ($isDue) {
                    if ($syncGroup !== '') {
                        $syncGroups[$syncGroup]['due'] = true;
                    } else {
                        $dueSecrets[] = array_merge($config, ['service_id' => $serviceId, 'trigger_type' => 'auto']);
                    }
                }
            }

            $hasDueGroup = !empty(array_filter($syncGroups, fn($g) => !empty($g['due'])));

            if (empty($dueSecrets) && !$hasDueGroup) {
                return Helpers::apiJson($response, 200, ['success' => true, 'rotated' => 0, 'errors' => [], 'message' => 'No secrets are due for rotation']);
            }

            $rotated = 0;
            $errors  = [];

            if (!empty($dueSecrets)) {
                $results = $this->rotator->rotateBatch($dueSecrets, $this->projectConfig->projectId, $this->projectConfig->environmentId);
                foreach ($results as $name => $result) {
                    if ($result === 'success') {
                        $svcId = null;
                        foreach ($dueSecrets as $item) {
                            if ($item['secret_name'] === $name) {
                                $svcId = $item['service_id'] ?: null;
                                break;
                            }
                        }
                        Helpers::invalidateVariableCache($this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, $svcId);
                        $rotated++;
                    } else {
                        $errors[] = $name;
                        $this->logger->error('Rotate all due error', ['secret' => $name, 'result' => $result]);
                    }
                }
            }

            foreach ($syncGroups as $groupName => $groupData) {
                if (empty($groupData['due'])) {
                    continue;
                }
                $success = $this->rotator->rotateSyncGroup($groupName, $this->projectConfig->projectId, $this->projectConfig->environmentId, null, 'auto');
                if (!$success) {
                    $errors[] = 'group:' . $groupName;
                    $this->logger->error('Rotate all due sync-group error', ['group' => $groupName]);
                    continue;
                }
                $uniqueScopes = [];
                foreach ($groupData['members'] as $member) {
                    $scope = (($member['service_id'] ?? '') !== '') ? (string)$member['service_id'] : '__global__';
                    $uniqueScopes[$scope] = ($scope === '__global__') ? null : $scope;
                    $rotated++;
                }
                foreach ($uniqueScopes as $scopeServiceId) {
                    Helpers::invalidateVariableCache($this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, $scopeServiceId);
                }
            }

            $message = "{$rotated} secret" . ($rotated === 1 ? '' : 's') . ' rotated';
            return Helpers::apiJson($response, 200, ['success' => true, 'rotated' => $rotated, 'errors' => $errors, 'message' => $message]);
        } catch (\InvalidArgumentException $e) {
            return Helpers::apiJson($response, 400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Rotate all due', ['error' => $e->getMessage()]);
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Internal server error']);
        }
    }

    // ── /api/secret-value ────────────────────────────────────────────────────

    public function secretValue(Request $request, Response $response): Response
    {
        $params     = $request->getQueryParams();
        $secretName = $params['name']      ?? '';
        $serviceId  = ($params['serviceId'] ?? '') ?: null;

        if (!Helpers::isValidSecretName($secretName)) {
            return Helpers::apiJson($response, 400, ['success' => false, 'error' => 'Invalid secret name']);
        }

        try {
            $variables = $this->railway->getVariables($this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId);
            if (!array_key_exists($secretName, $variables)) {
                return Helpers::apiJson($response, 404, ['success' => false, 'error' => 'Secret not found']);
            }
            return Helpers::apiJson($response, 200, ['success' => true, 'value' => $variables[$secretName]]);
        } catch (\Exception $e) {
            $this->logger->error('Secret value API error', ['error' => $e->getMessage()]);
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Internal server error']);
        }
    }

    // ── /api/sync-group-config POST ──────────────────────────────────────────

    public function syncGroupConfigSave(Request $request, Response $response): Response
    {
        $body = (array)($request->getParsedBody() ?? []);

        try {
            if (!$this->session->validateCsrfToken($body['csrf_token'] ?? null)) {
                return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
            $groupName    = Helpers::normalizeSyncGroup($body['group_name'] ?? null);
            if ($groupName === null) {
                throw new \InvalidArgumentException('Group name is required');
            }
            $length       = Helpers::normalizeLength(isset($body['length']) ? (int)$body['length'] : null) ?? 32;
            $encoding     = Helpers::normalizeEncoding($body['encoding'] ?? null) ?? 'hex';
            $interval     = max(0, (int)($body['interval'] ?? 0));
            $intervalUnit = Helpers::normalizeUnit($body['interval_unit'] ?? null);

            $this->storage->saveSyncGroupConfig($groupName, [
                'length'        => $length,
                'encoding'      => $encoding,
                'interval_days' => $interval,
                'interval_unit' => $intervalUnit,
            ]);

            $response = $response->withHeader('HX-Trigger', (string)json_encode(['rotatorToast' => ['message' => 'Group policy updated', 'type' => 'success']]));
            return Helpers::apiJson($response, 200, ['success' => true, 'group' => $groupName]);
        } catch (\InvalidArgumentException $e) {
            return Helpers::apiJson($response, 400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Sync group config API error', ['error' => $e->getMessage()]);
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Internal server error']);
        }
    }

    // ── /api/sync-group-config DELETE ───────────────────────────────────────

    public function syncGroupConfigDelete(Request $request, Response $response): Response
    {
        $body = [];
        parse_str((string)$request->getBody(), $body);

        try {
            if (!$this->session->validateCsrfToken($body['csrf_token'] ?? null)) {
                return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
            $groupName = Helpers::normalizeSyncGroup($body['group_name'] ?? null);
            if ($groupName === null) {
                throw new \InvalidArgumentException('Group name is required');
            }
            $this->storage->deleteSyncGroup($groupName);
            $response = $response->withHeader('HX-Trigger', (string)json_encode(['rotatorToast' => ['message' => "Group \"{$groupName}\" deleted — members are now independent", 'type' => 'success']]));
            return Helpers::apiJson($response, 200, ['success' => true, 'group' => $groupName]);
        } catch (\InvalidArgumentException $e) {
            return Helpers::apiJson($response, 400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Delete sync group API error', ['error' => $e->getMessage()]);
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Internal server error']);
        }
    }

    // ── /api/config-form ─────────────────────────────────────────────────────

    public function configForm(Request $request, Response $response): Response
    {
        $params     = $request->getQueryParams();
        $secretName = $params['name']      ?? '';
        $serviceId  = ($params['serviceId'] ?? '') ?: null;

        if (!Helpers::isValidSecretName($secretName)) {
            $response->getBody()->write('Invalid secret name');
            return $response->withStatus(400);
        }

        try {
            $managed          = $this->storage->getManagedSecrets();
            $keyId            = ($serviceId ?: 'global') . ':' . $secretName;
            $config           = $managed[$keyId] ?? null;
            $syncGroups       = $this->storage->getDistinctSyncGroups();
            $syncGroupConfigs = $this->storage->getSyncGroupConfigMap();
            $csrfToken        = (string)$request->getAttribute('csrfToken', '');

            ob_start();
            include dirname(__DIR__) . '/Views/components/config-modal-form.php';
            $response->getBody()->write((string)ob_get_clean());
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\Exception $e) {
            $this->logger->error('Config form error', ['error' => $e->getMessage()]);
            $response->getBody()->write('Internal server error');
            return $response->withStatus(500);
        }
    }

    // ── /api/rotate-form ─────────────────────────────────────────────────────

    public function rotateForm(Request $request, Response $response): Response
    {
        $params     = $request->getQueryParams();
        $secretName = $params['name']      ?? '';
        $serviceId  = ($params['serviceId'] ?? '') ?: null;

        if (!Helpers::isValidSecretName($secretName)) {
            $response->getBody()->write('Invalid secret name');
            return $response->withStatus(400);
        }

        try {
            $managed          = $this->storage->getManagedSecrets();
            $keyId            = ($serviceId ?: 'global') . ':' . $secretName;
            $config           = $managed[$keyId] ?? null;
            $syncGroupMembers = [];
            $csrfToken        = (string)$request->getAttribute('csrfToken', '');

            $syncGroup = trim((string)($config['sync_group'] ?? ''));
            if ($syncGroup !== '') {
                $serviceNameMap = $this->storage->getServiceNameMapFromMetadata();
                foreach ($this->storage->getSyncGroupMembers($syncGroup) as $member) {
                    $memberName      = (string)($member['secret_name'] ?? '');
                    $memberServiceId = (($member['service_id'] ?? '') !== '' ? (string)$member['service_id'] : null);
                    $sameAsCurrent   = $memberName === $secretName && (string)($memberServiceId ?? '') === (string)($serviceId ?? '');
                    if ($sameAsCurrent) {
                        continue;
                    }
                    $serviceLabel       = $memberServiceId === null ? 'Global' : ($serviceNameMap[$memberServiceId] ?? $memberServiceId);
                    $syncGroupMembers[] = ['secret_name' => $memberName, 'service' => $serviceLabel];
                }
            }

            ob_start();
            include dirname(__DIR__) . '/Views/components/rotate-modal-form.php';
            $response->getBody()->write((string)ob_get_clean());
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\Exception $e) {
            $this->logger->error('Rotate form error', ['error' => $e->getMessage()]);
            $response->getBody()->write('Internal server error');
            return $response->withStatus(500);
        }
    }

    // ── /api/config POST ─────────────────────────────────────────────────────

    public function configSave(Request $request, Response $response): Response
    {
        $body      = (array)($request->getParsedBody() ?? []);
        $name      = $body['name']      ?? '';
        $serviceId = ($body['serviceId'] ?? '') ?: null;
        $mode      = $body['mode']      ?? 'save_and_rotate';

        try {
            if (!$this->session->validateCsrfToken($body['csrf_token'] ?? null)) {
                $response->getBody()->write('Invalid CSRF token');
                return $response->withStatus(403);
            }
            if (!Helpers::isValidSecretName($name)) {
                $response->getBody()->write('Invalid secret name');
                return $response->withStatus(400);
            }
            if (!in_array($mode, ['rotate_only', 'save_only', 'save_and_rotate'], true)) {
                throw new \InvalidArgumentException('Invalid operation mode');
            }

            $length      = Helpers::normalizeLength(isset($body['length']) ? (int)$body['length'] : null);
            $encoding    = Helpers::normalizeEncoding($body['encoding'] ?? null);
            $syncGroup   = Helpers::normalizeSyncGroup($body['sync_group'] ?? null);
            $interval    = max(0, (int)($body['interval'] ?? 0));
            $intervalUnit = Helpers::normalizeUnit($body['interval_unit'] ?? null);

            if ($mode === 'save_only' || $mode === 'save_and_rotate') {
                $this->storage->saveConfig($name, $serviceId, [
                    'length'        => $length ?? 32,
                    'encoding'      => $encoding ?? 'hex',
                    'sync_group'    => $syncGroup,
                    'interval_days' => $interval,
                    'interval_unit' => $intervalUnit,
                ]);
            }

            $manualValue = isset($body['manual_value']) ? trim((string)$body['manual_value']) : null;
            $manualValue = ($manualValue === '') ? null : $manualValue;
            if ($manualValue !== null && strlen($manualValue) > 4096) {
                throw new \InvalidArgumentException('Manual value is too large');
            }

            if ($mode === 'rotate_only' || $mode === 'save_and_rotate') {
                $this->rotator->rotate($name, $this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId, $manualValue, $length, $encoding);
                Helpers::invalidateVariableCache($this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId);
            }

            $toastMessage = match ($mode) {
                'save_only'       => 'Config saved',
                'rotate_only'     => 'Secret rotated',
                'save_and_rotate' => 'Config saved and secret rotated',
                default           => 'Updated successfully',
            };
            $response = $response->withHeader('HX-Trigger', (string)json_encode(['rotatorToast' => ['message' => $toastMessage, 'type' => 'success']]));

            $variables = Helpers::getVariablesCached($this->railway, $this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId, false);
            $managed   = $this->storage->getManagedSecrets();
            $csrfToken = (string)$request->getAttribute('csrfToken', '');

            ob_start();
            include dirname(__DIR__) . '/Views/components/secrets-table-body.php';
            $response->getBody()->write((string)ob_get_clean());
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\InvalidArgumentException $e) {
            $response = $response->withStatus(400)->withHeader('HX-Trigger', (string)json_encode(['rotatorToast' => ['message' => $e->getMessage(), 'type' => 'error']]));
            $response->getBody()->write(htmlspecialchars($e->getMessage()));
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\Exception $e) {
            $this->logger->error('Config API error', ['error' => $e->getMessage()]);
            $response = $response->withStatus(500)->withHeader('HX-Trigger', (string)json_encode(['rotatorToast' => ['message' => 'Internal server error', 'type' => 'error']]));
            $response->getBody()->write('Internal server error');
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
    }

    // ── /api/config DELETE ───────────────────────────────────────────────────

    public function configDelete(Request $request, Response $response): Response
    {
        $body      = [];
        parse_str((string)$request->getBody(), $parsed);
        $params    = $request->getQueryParams();
        $name      = $params['name']      ?? $parsed['name']      ?? '';
        $rawSvcId  = $params['serviceId'] ?? $parsed['serviceId'] ?? '';
        $serviceId = ($rawSvcId !== '') ? $rawSvcId : null;
        $csrf      = $params['csrf_token'] ?? $parsed['csrf_token'] ?? null;

        try {
            if (!$this->session->validateCsrfToken($csrf)) {
                $response->getBody()->write('Invalid CSRF token');
                return $response->withStatus(403);
            }
            if (!Helpers::isValidSecretName($name)) {
                $response->getBody()->write('Invalid secret name');
                return $response->withStatus(400);
            }
            $this->storage->deleteConfig($name, $serviceId);
            $response = $response->withHeader('HX-Trigger', (string)json_encode(['rotatorToast' => ['message' => 'Config removed', 'type' => 'success']]));

            $variables = Helpers::getVariablesCached($this->railway, $this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId, false);
            $managed   = $this->storage->getManagedSecrets();
            $csrfToken = (string)$request->getAttribute('csrfToken', '');

            ob_start();
            include dirname(__DIR__) . '/Views/components/secrets-table-body.php';
            $response->getBody()->write((string)ob_get_clean());
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\Exception $e) {
            $this->logger->error('Delete config error', ['error' => $e->getMessage()]);
            $response->getBody()->write('Internal server error');
            return $response->withStatus(500);
        }
    }

    // ── /api/secrets-table ───────────────────────────────────────────────────

    public function secretsTable(Request $request, Response $response): Response
    {
        $params       = $request->getQueryParams();
        $serviceId    = ($params['serviceId'] ?? '') ?: null;
        $forceRefresh = ($params['refresh'] ?? '0') === '1';
        $csrfToken    = (string)$request->getAttribute('csrfToken', '');

        try {
            $variables = Helpers::getVariablesCached($this->railway, $this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId, $forceRefresh);
            $managed   = $this->storage->getManagedSecrets();

            ob_start();
            include dirname(__DIR__) . '/Views/components/secrets-table-body.php';
            $response->getBody()->write((string)ob_get_clean());
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\Exception $e) {
            $this->logger->error('Table refresh error', ['error' => $e->getMessage()]);
            $response->getBody()->write('Internal server error');
            return $response->withStatus(500);
        }
    }

    // ── /api/rotation-history ────────────────────────────────────────────────

    public function rotationHistory(Request $request, Response $response): Response
    {
        $params    = $request->getQueryParams();
        $serviceId = ($params['serviceId'] ?? '') ?: null;

        try {
            $services       = Helpers::getServicesCached($this->railway, $this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, false);
            $serviceNameMap = Helpers::buildServiceNameMap($services);
            $recentHistory  = $this->storage->getRecentHistory($serviceId, 30);
            $csrfToken      = (string)$request->getAttribute('csrfToken', '');

            ob_start();
            include dirname(__DIR__) . '/Views/components/rotation-history-body.php';
            $response->getBody()->write((string)ob_get_clean());
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\Exception $e) {
            $this->logger->error('Rotation history API error', ['error' => $e->getMessage()]);
            $response->getBody()->write('Internal server error');
            return $response->withStatus(500);
        }
    }

    // ── /api/rotation-history-detail ────────────────────────────────────────

    public function rotationHistoryDetail(Request $request, Response $response): Response
    {
        $params    = $request->getQueryParams();
        $historyId = (int)($params['id'] ?? 0);

        try {
            if ($historyId <= 0) {
                throw new \InvalidArgumentException('Invalid history id');
            }
            $detail = $this->storage->getHistoryDetailById($historyId);
            if ($detail === null) {
                return Helpers::apiJson($response, 404, ['success' => false, 'error' => 'History entry not found']);
            }

            $serviceId    = (string)($detail['service_id'] ?? '');
            $serviceLabel = 'Global Variables';
            if ($serviceId !== '') {
                $services       = Helpers::getServicesCached($this->railway, $this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, false);
                $serviceNameMap = Helpers::buildServiceNameMap($services);
                $serviceLabel   = $serviceNameMap[$serviceId] ?? $serviceId;
            }

            $newValue      = $detail['new_value'];
            $newValueIsLive = false;
            if ($newValue === null && ($params['fetch_live'] ?? '') === '1') {
                try {
                    $currentVars = $this->railway->getVariables($this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId !== '' ? $serviceId : null);
                    $secretName  = (string)$detail['secret_name'];
                    if (isset($currentVars[$secretName])) {
                        $newValue       = $currentVars[$secretName];
                        $newValueIsLive = true;
                    }
                } catch (\Throwable $e) {
                    // Leave null — not fatal
                }
            }

            return Helpers::apiJson($response, 200, [
                'success' => true,
                'data'    => [
                    'id'              => (int)$detail['id'],
                    'secret_name'     => (string)$detail['secret_name'],
                    'service'         => $serviceLabel,
                    'rotated_at'      => (string)$detail['rotated_at'],
                    'trigger_type'    => (string)($detail['trigger_type'] ?? 'manual'),
                    'old_value'       => $detail['old_value'],
                    'new_value'       => $newValue,
                    'new_value_is_live' => $newValueIsLive,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return Helpers::apiJson($response, 400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Rotation history detail API error', ['error' => $e->getMessage()]);
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Internal server error']);
        }
    }

    // ── /api/cache/refresh ───────────────────────────────────────────────────

    public function cacheRefresh(Request $request, Response $response): Response
    {
        $body      = (array)($request->getParsedBody() ?? []);
        $scope     = $body['scope']     ?? 'all';
        $serviceId = $body['serviceId'] ?? null;

        try {
            if (!$this->session->validateCsrfToken($body['csrf_token'] ?? null)) {
                throw new \InvalidArgumentException('Invalid CSRF token');
            }
            if (!in_array($scope, ['services', 'variables', 'all'], true)) {
                throw new \InvalidArgumentException('Invalid cache scope');
            }
            if ($scope === 'services' || $scope === 'all') {
                $this->cache->delete(Helpers::cacheKeyServices($this->projectConfig->projectId, $this->projectConfig->environmentId));
                Helpers::getServicesCached($this->railway, $this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, true);
            }
            if ($scope === 'variables' || $scope === 'all') {
                if ($scope === 'all') {
                    $this->cache->deletePrefix(sprintf('variables:%s:%s:', $this->projectConfig->projectId, $this->projectConfig->environmentId));
                } else {
                    Helpers::invalidateVariableCache($this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId ?: null);
                }
                Helpers::getVariablesCached($this->railway, $this->cache, $this->projectConfig->projectId, $this->projectConfig->environmentId, $serviceId ?: null, true);
            }
            return Helpers::apiJson($response, 200, ['success' => true, 'message' => 'Cache refreshed']);
        } catch (\InvalidArgumentException $e) {
            return Helpers::apiJson($response, 400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Cache refresh API error', ['error' => $e->getMessage()]);
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Internal server error']);
        }
    }

    // ── /api/service-group ───────────────────────────────────────────────────

    public function serviceGroup(Request $request, Response $response): Response
    {
        $body = (array)($request->getParsedBody() ?? []);

        try {
            if (!$this->session->validateCsrfToken($body['csrf_token'] ?? null)) {
                throw new \InvalidArgumentException('Invalid CSRF token');
            }
            $serviceId = trim((string)($body['serviceId'] ?? ''));
            $groupName = isset($body['groupName']) ? trim((string)$body['groupName']) : null;
            if ($serviceId === '') {
                throw new \InvalidArgumentException('Invalid service id');
            }
            $this->storage->setServiceGroup($serviceId, $groupName);
            return Helpers::apiJson($response, 200, ['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return Helpers::apiJson($response, 400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Service group API error', ['error' => $e->getMessage()]);
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Internal server error']);
        }
    }

    // ── /api/service-group/bulk ──────────────────────────────────────────────

    public function serviceGroupBulk(Request $request, Response $response): Response
    {
        $body = (array)($request->getParsedBody() ?? []);

        try {
            if (!$this->session->validateCsrfToken($body['csrf_token'] ?? null)) {
                throw new \InvalidArgumentException('Invalid CSRF token');
            }
            $groupName  = trim((string)($body['groupName'] ?? ''));
            if ($groupName === '') {
                throw new \InvalidArgumentException('Group name is required');
            }
            $serviceIds = is_array($body['serviceIds'] ?? null) ? $body['serviceIds'] : [$body['serviceIds'] ?? ''];
            $normalized = array_filter(array_map('trim', array_map('strval', $serviceIds)));
            if (empty($normalized)) {
                throw new \InvalidArgumentException('Select at least one service');
            }
            foreach ($normalized as $serviceId) {
                $this->storage->setServiceGroup($serviceId, $groupName);
            }
            return Helpers::apiJson($response, 200, ['success' => true, 'updated' => count($normalized)]);
        } catch (\InvalidArgumentException $e) {
            return Helpers::apiJson($response, 400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('Bulk service group API error', ['error' => $e->getMessage()]);
            return Helpers::apiJson($response, 500, ['success' => false, 'error' => 'Internal server error']);
        }
    }
}
