<?php

// Load .env — safeLoad() is a no-op when the file is absent (production).
// createUnsafeImmutable() also calls putenv() so getenv() works everywhere.
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
    $dotenv->safeLoad();
}

/**
 * Escape a string for safe HTML output (ENT_QUOTES + HTML5 + UTF-8).
 * Use this everywhere instead of bare htmlspecialchars().
 */
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Developer / app-level links — single source of truth.
 * Update these values here; they propagate to the footer, About page, etc.
 */
$appConfig = [
    'dev' => [
        'name'      => 'Devendra Pratap Singh',
        'handle'    => '@0xdps',
        'avatar'    => 'https://avatars.githubusercontent.com/u/5993833?v=4',
        'bio'       => 'Builder of small tools that scratch real itches. Mostly PHP, Go, and whatever the problem calls for. Runs too many side projects on Railway.',
        'portfolio' => 'https://dps.codes',
        'github'    => 'https://github.com/0xdps',
        'support'   => 'https://buymeacoffee.com/0xdps',
    ],
    'repo' => [
        'source'  => 'https://github.com/0xdps/railway-secrets',
        'license' => 'https://github.com/0xdps/railway-secrets/blob/trunk/LICENSE',
        'star'    => 'https://github.com/0xdps/railway-secrets',
    ],
];

/**
 * Get configuration for a specific time unit.
 * @return array{divisor: int, label: string, suffix: string}
 */
function getUnitConfig(string $unit): array
{
    $map = [
        'minute' => ['divisor' => 60,    'label' => 'minutes', 'suffix' => 'm'],
        'hour'   => ['divisor' => 3600,  'label' => 'hours',   'suffix' => 'h'],
        'day'    => ['divisor' => 86400, 'label' => 'days',    'suffix' => 'd'],
    ];
    return $map[$unit] ?? $map['day'];
}


