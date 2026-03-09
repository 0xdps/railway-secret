<?php
/**
 * About View - uses shared layout
 *
 * Required variables (from public/index.php):
 * @var string $csrfToken
 * @var array  $groupedServices
 */

$pageScript = 'dashboard.js';

$serviceId = null;
$section   = 'about';
$viewTitle = 'About';

$contentCallback = function () {
    include __DIR__ . '/components/about-content.php';
};

include __DIR__ . '/layout.php';
