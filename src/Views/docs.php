<?php
/**
 * Documentation View - uses shared layout
 * 
 * Required variables (from public/index.php):
 * @var string $csrfToken
 * @var array $groupedServices
 */

$pageScript = 'docs.js';

// All docs styles live in style.css — no inline styles needed.
$extraHead = '<script src="/js/app.js" type="module"></script>';

$serviceId = null;
$section = 'docs';
$viewTitle = 'Docs';

$contentCallback = function() {
    include __DIR__ . '/components/docs-content.php';
};

include __DIR__ . '/layout.php';
