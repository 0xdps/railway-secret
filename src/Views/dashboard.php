<?php
/**
 * Dashboard View - uses shared layout
 * 
 * Required variables (from public/index.php):
 * @var string $viewTitle
 * @var string $csrfToken
 * @var string|null $serviceId
 * @var string $section
 * @var array $groupedServices
 * @var array $serviceNameMap
 * @var array $variables
 * @var array $managed
 * @var array $recentHistory
 * @var int $cacheFetchedAt
 * @var array $timeConfig
 */

$pageScript = 'dashboard.js';

$contentCallback = function() use ($viewTitle, $csrfToken, $serviceId, $section, $variables, $managed, $recentHistory, $cacheFetchedAt, $timeConfig, $serviceCount) {
    include __DIR__ . '/components/dashboard-main.php';
};

include __DIR__ . '/layout.php';

