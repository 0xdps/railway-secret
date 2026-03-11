<?php
/**
 * About View - uses shared layout
 *
 * Required variables (from public/index.php):
 * @var string $csrfToken
 * @var array  $groupedServices
 */

$pageScript = 'docs.js';

$extraHead = '<script src="/js/app.js" type="module"></script>';

$serviceId = null;
$section   = 'about';
$viewTitle = 'About';

$contentCallback = function () {
    include __DIR__ . '/components/about-content.php';
};

include __DIR__ . '/layout.php';
