<?php

declare(strict_types=1);

namespace App;

use App\Service\RailwayClient;
use App\Service\RailwayCacheService;
use Psr\Http\Message\ResponseInterface;

// Cache TTLs — kept here so they're next to the helpers that use them
const CACHE_TTL_SERVICES_SECONDS  = 3600;
const CACHE_TTL_VARIABLES_SECONDS = 3600;

class Helpers
{
    // ── Environment ─────────────────────────────────────────────────────────

    public static function requireEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            throw new \RuntimeException("Missing required configuration: {$name}");
        }
        return $value;
    }

    // ── PSR-7 response helpers ───────────────────────────────────────────────

    public static function apiJson(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write((string)json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public static function htmlResponse(ResponseInterface $response, string $html, int $status = 200): ResponseInterface
    {
        $response->getBody()->write($html);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    // ── Input validation / normalisation ────────────────────────────────────

    public static function isValidSecretName(string $name): bool
    {
        return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
    }

    public static function normalizeLength(?int $length): ?int
    {
        if ($length === null) {
            return null;
        }
        if ($length < 8 || $length > 256) {
            throw new \InvalidArgumentException('Length must be between 8 and 256');
        }
        return $length;
    }

    public static function normalizeEncoding(?string $encoding): ?string
    {
        if ($encoding === null || $encoding === '') {
            return null;
        }
        if (!in_array($encoding, ['hex', 'base64', 'alphanumeric'], true)) {
            throw new \InvalidArgumentException('Invalid encoding option');
        }
        return $encoding;
    }

    public static function normalizeUnit(?string $unit): string
    {
        return in_array($unit, ['minute', 'hour', 'day'], true) ? (string)$unit : 'day';
    }

    /**
     * Seconds between rotations. Zero means manual-only.
     */
    public static function intervalSeconds(int $interval, string $unit): int
    {
        if ($interval <= 0) {
            return 0;
        }
        $divisorMap = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
        return $interval * ($divisorMap[$unit] ?? 86400);
    }

    /**
     * Next run is one full interval after $now. Overdue work does not catch up
     * by repeating inside the missed windows.
     */
    public static function nextRotationAt(int $interval, string $unit, ?int $now = null): ?string
    {
        $seconds = self::intervalSeconds($interval, $unit);
        if ($seconds <= 0) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', ($now ?? time()) + $seconds);
    }

    public static function normalizeSyncGroup(?string $syncGroup): ?string
    {
        $syncGroup = trim((string)$syncGroup);
        if ($syncGroup === '') {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/', $syncGroup)) {
            throw new \InvalidArgumentException(
                'Sync group can contain letters, numbers, dot, dash, underscore (max 64 chars)'
            );
        }
        return $syncGroup;
    }

    // ── Cache key helpers ───────────────────────────────────────────────────

    public static function cacheKeyServices(string $projectId, string $environmentId): string
    {
        return 'services:' . $projectId . ':' . $environmentId;
    }

    public static function cacheKeyVariables(string $projectId, string $environmentId, ?string $serviceId): string
    {
        return sprintf('variables:%s:%s:%s', $projectId, $environmentId, $serviceId ?: 'global');
    }

    // ── Cached Railway data ─────────────────────────────────────────────────

    public static function getServicesCached(
        RailwayClient $railway,
        RailwayCacheService $cache,
        string $projectId,
        string $environmentId,
        bool $forceRefresh = false
    ): array {
        $key = self::cacheKeyServices($projectId, $environmentId);
        if (!$forceRefresh) {
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $services = $railway->getServices($projectId, $environmentId);
        $cache->put($key, $services, CACHE_TTL_SERVICES_SECONDS);
        return $services;
    }

    public static function getVariablesCached(
        RailwayClient $railway,
        RailwayCacheService $cache,
        string $projectId,
        string $environmentId,
        ?string $serviceId,
        bool $forceRefresh = false
    ): array {
        $key = self::cacheKeyVariables($projectId, $environmentId, $serviceId);
        if (!$forceRefresh) {
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $variables = $railway->getVariables($projectId, $environmentId, $serviceId);
        $names = array_keys($variables);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $cache->put($key, $names, CACHE_TTL_VARIABLES_SECONDS);
        return $names;
    }

    public static function invalidateVariableCache(
        RailwayCacheService $cache,
        string $projectId,
        string $environmentId,
        ?string $serviceId
    ): void {
        $cache->delete(self::cacheKeyVariables($projectId, $environmentId, $serviceId));
    }

    // ── Service / group data helpers ────────────────────────────────────────

    public static function buildGroupedServices(array $services, array $groupMap): array
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
            $serviceId   = (string)($service['id']   ?? '');
            $serviceName = (string)($service['name'] ?? '');
            if ($serviceId === '' || $serviceName === '') {
                continue;
            }
            $group = trim((string)($groupMap[$serviceId] ?? ''));
            if ($group === '') {
                $group = $hasCustomGroups ? 'Ungrouped' : 'Services';
            }
            $grouped[$group][] = $service;
        }

        ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($grouped as $group => $items) {
            usort($items, static fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
            $grouped[$group] = $items;
        }

        return $grouped;
    }

    public static function buildServiceNameMap(array $services): array
    {
        $map = [];
        foreach ($services as $service) {
            $id   = (string)($service['id']   ?? '');
            $name = (string)($service['name'] ?? '');
            if ($id !== '' && $name !== '') {
                $map[$id] = $name;
            }
        }
        return $map;
    }

    // ── IP helpers ──────────────────────────────────────────────────────────

    public static function getClientIp(): string
    {
        $remote = self::validIp($_SERVER['REMOTE_ADDR'] ?? '') ?? 'unknown';

        // PHP-FPM is reached from local nginx. nginx overwrites X-Real-IP with
        // the TCP peer it actually accepted, so a client-supplied header is ignored.
        if (self::isLoopback($remote)) {
            $observed = self::validIp($_SERVER['HTTP_X_REAL_IP'] ?? '');
            if ($observed !== null) {
                $remote = $observed;
            }
        }

        if (!self::isTrustedProxy($remote)) {
            return $remote;
        }

        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($forwarded) || $forwarded === '') {
            return $remote;
        }

        $parts = array_map('trim', explode(',', $forwarded));
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $candidate = self::validIp($parts[$i]);
            if ($candidate === null) {
                continue;
            }
            if (!self::isTrustedProxy($candidate)) {
                return $candidate;
            }
        }

        return $remote;
    }

    public static function isTrustedProxy(string $ip): bool
    {
        if ($ip === '' || self::validIp($ip) === null) {
            return false;
        }
        $trusted = trim((string)(getenv('TRUSTED_PROXY_IPS') ?: ''));
        if ($trusted === '') {
            return false;
        }
        foreach (explode(',', $trusted) as $candidate) {
            if (trim($candidate) === $ip) {
                return true;
            }
        }
        return false;
    }

    private static function isLoopback(string $ip): bool
    {
        return $ip === '127.0.0.1' || $ip === '::1';
    }

    private static function validIp(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return $value;
    }
}
