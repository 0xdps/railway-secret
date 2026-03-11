<?php

declare(strict_types=1);

namespace App\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class RailwayClient
{
    private string $endpoint = 'https://backboard.railway.app/graphql/v2';

    public function __construct(
        private readonly string          $token,
        private readonly GuzzleClient    $client,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Generic GraphQL request
     */
    private function request(string $query, array $variables = []): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if (str_starts_with($this->token, 'pt_')) {
            $headers['Project-Access-Token'] = $this->token;
        } else {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        try {
            $res = $this->client->post($this->endpoint, [
                'headers' => $headers,
                'json'    => ['query' => $query, 'variables' => $variables],
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('Railway API request failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Railway API request failed: ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string)$res->getBody(), true);
        if (isset($data['errors'])) {
            $msg = $data['errors'][0]['message'] ?? 'Unknown API error';
            throw new \RuntimeException('Railway API Error: ' . $msg);
        }

        return $data['data'] ?? [];
    }

    /**
     * Fetch all services in the project
     */
    public function getServices(string $projectId): array
    {
        $query = '
        query GetServices($projectId: String!) {
          project(id: $projectId) {
            services {
              edges {
                node {
                  id
                  name
                }
              }
            }
          }
        }';

        $data = $this->request($query, ['projectId' => $projectId]);
        $services = [];
        foreach ($data['project']['services']['edges'] ?? [] as $edge) {
            $services[] = $edge['node'];
        }
        return $services;
    }

    /**
     * Fetch variables for the project/environment OR a specific service
     */
    public function getVariables(string $projectId, string $environmentId, ?string $serviceId = null): array
    {
        if (!empty($serviceId)) {
            // Newer Railway schemas expose service variables via the top-level
            // `variables` field with an optional `serviceId` argument.
            $query = '
            query GetScopedVariables($projectId: String!, $environmentId: String!, $serviceId: String) {
              variables(projectId: $projectId, environmentId: $environmentId, serviceId: $serviceId)
            }';

            try {
                return $this->request($query, [
                    'projectId' => $projectId,
                    'environmentId' => $environmentId,
                    'serviceId' => $serviceId,
                ])['variables'] ?? [];
            } catch (\Exception $e) {
                // Backward compatibility fallback for older schemas.
                $legacyQuery = '
                query GetServiceVariables($serviceId: String!, $environmentId: String!) {
                  service(id: $serviceId) {
                    variables(environmentId: $environmentId)
                  }
                }';

                $data = $this->request($legacyQuery, [
                    'serviceId' => $serviceId,
                    'environmentId' => $environmentId,
                ]);
                return $data['service']['variables'] ?? [];
            }
        }

        $query = '
        query GetGlobalVariables($projectId: String!, $environmentId: String!) {
          variables(projectId: $projectId, environmentId: $environmentId)
        }';

        return $this->request($query, [
            'projectId' => $projectId,
            'environmentId' => $environmentId
        ])['variables'] ?? [];
    }

    /**
     * Upsert a variable (This triggers redeploy in Railway)
     */
    public function upsertVariable(string $projectId, string $environmentId, string $name, string $value, ?string $serviceId = null): bool
    {
        $query = '
        mutation VariableUpsert($input: VariableUpsertInput!) {
          variableUpsert(input: $input)
        }';

        $input = [
            'projectId' => $projectId,
            'environmentId' => $environmentId,
            'name' => $name,
            'value' => $value
        ];

        if ($serviceId) {
            $input['serviceId'] = $serviceId;
        }

        return $this->request($query, ['input' => $input])['variableUpsert'] ?? false;
    }

    /**
     * Upsert multiple variables in one API call, triggering a single redeploy
     * instead of one redeploy per variable.
     *
     * @param array $vars  [ 'VAR_NAME' => 'value', ... ]
     */
    public function upsertVariables(string $projectId, string $environmentId, array $vars, ?string $serviceId = null): bool
    {
        $query = '
        mutation VariableCollectionUpsert($input: VariableCollectionUpsertInput!) {
          variableCollectionUpsert(input: $input)
        }';

        $input = [
            'projectId'     => $projectId,
            'environmentId' => $environmentId,
            'variables'     => $vars,
        ];

        if ($serviceId) {
            $input['serviceId'] = $serviceId;
        }

        return $this->request($query, ['input' => $input])['variableCollectionUpsert'] ?? false;
    }
}
