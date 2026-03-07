<?php

namespace App\Service;

class RailwayClient
{
    private string $token;
    private string $endpoint = 'https://backboard.railway.app/graphql/v2';

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Generic GraphQL request
     */
    private function request(string $query, array $variables = []): array
    {
        $payload = json_encode(['query' => $query, 'variables' => $variables]);
        $ch = curl_init($this->endpoint);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $headers = ['Content-Type: application/json'];
        if (strpos($this->token, 'pt_') === 0) {
            $headers[] = 'Project-Access-Token: ' . $this->token;
        } else {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close($ch); is deprecated in 8.5+ and unnecessary in 8.0+

        if ($error) {
            error_log("Railway API Client CURL Error: $error");
            throw new \Exception("CURL Error: " . $error);
        }

        $data = json_decode($response, true);
        if ($httpCode >= 400 || isset($data['errors'])) {
            $msg = $data['errors'][0]['message'] ?? "HTTP $httpCode";
            throw new \Exception("Railway API Error: " . $msg);
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
            $query = '
            query GetServiceVariables($serviceId: String!, $environmentId: String!) {
              service(id: $serviceId) {
                variables(environmentId: $environmentId)
              }
            }';
            $data = $this->request($query, [
                'serviceId' => $serviceId,
                'environmentId' => $environmentId
            ]);
            return $data['service']['variables'] ?? [];
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
}
