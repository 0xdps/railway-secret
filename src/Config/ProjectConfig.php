<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Immutable value object holding the Railway project/environment identifiers
 * that are required by most controllers and API handlers.
 */
final class ProjectConfig
{
    public function __construct(
        public readonly string $projectId,
        public readonly string $environmentId,
    ) {}
}
