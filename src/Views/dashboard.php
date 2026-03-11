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
 */

$pageScript = 'app.js';

$contentCallback = function() use ($viewTitle, $csrfToken, $serviceId, $section, $variables, $managed, $recentHistory, $cacheFetchedAt, $serviceCount, $rotations24h) {
    include __DIR__ . '/components/dashboard-main.php';
};

include __DIR__ . '/layout.php';

