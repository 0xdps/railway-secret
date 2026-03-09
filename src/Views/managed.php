<?php
/**
 * Managed Secrets Overview View — uses shared layout
 *
 * @var string $viewTitle
 * @var string $csrfToken
 * @var string $section
 * @var array  $groupedServices
 * @var array  $allManaged
 * @var array  $serviceNameMap
 */

$pageScript = 'dashboard.js';
$serviceId  = null;

$contentCallback = function () use ($viewTitle, $csrfToken, $section, $allManaged) {
    include __DIR__ . '/components/managed-main.php';
};

include __DIR__ . '/layout.php';
