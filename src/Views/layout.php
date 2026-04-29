<?php
/**
 * Base Layout Template
 * 
 * Required variables:
 * @var string $viewTitle - Page title
 * @var string|null $csrfToken - CSRF token
 * @var string|null $serviceId - Current service ID
 * @var string $section - Current section (secrets/history/etc)
 * @var array $groupedServices - Services organized by group
 * @var string $pageScript - Script file to load (e.g., dashboard.js, docs.js)
 * @var string $extraHead - Additional head content (optional)
 * @var callable $contentCallback - Function that renders main content
 * @var boolean $isAuthenticated - Whether user is logged in
 */
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title><?= h($viewTitle) ?> — Railway Secrets</title>
    <link rel="stylesheet" href="/style.css">
    <script src="https://unpkg.com/lucide@0.577.0/dist/umd/lucide.js" integrity="sha384-1MrOtYSDnlvNAr6rHFMYrjwqLm+8lCPz+suIruDTmum9JoBgagrhFzxveKunHj30" crossorigin="anonymous"></script>
    <script src="https://unpkg.com/htmx.org@2.0.4" integrity="sha384-HGfztofotfshcF7+8n44JQL2oJmowVChPTg48S+jvZoztPfvwD79OC/LTtG6dMp+" crossorigin="anonymous" defer></script>
    <?php if (isset($extraHead)): echo $extraHead; endif; ?>
    <script src="/js/<?= h($pageScript) ?>"<?= $pageScript === 'app.js' ? ' type="module"' : ' defer' ?>></script>
</head>
<body data-current-service-id="<?= h((string)($serviceId ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-csrf-token="<?= h($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
<div class="app-layout">

    <!-- ── Sidebar ────────────────────────────────────────────────── -->
    <?php include __DIR__ . '/components/sidebar.php'; ?>

    <!-- ── Main Content ───────────────────────────────────────────── -->
    <?php $contentCallback(); ?>

    <!-- ── Loading Indicator ───────────────────────────────────────── -->
    <div id="mainContentLoading" class="main-content-loading htmx-indicator" aria-hidden="true">
        <div class="main-content-loading-inner">
            <i data-lucide="loader-circle" class="main-loading-spinner" style="width:18px;height:18px;"></i>
            <span id="loadingText">Loading...</span>
        </div>
    </div>

</div><!-- /app-layout -->

<!-- ── Site Footer ──────────────────────────────────────────────── -->
<?php global $appConfig; $dev = $appConfig['dev']; $repo = $appConfig['repo']; ?>
<footer class="site-footer">
    <span class="site-footer-credit">
        Made with <span class="footer-heart">&#9829;</span> by
        <a href="<?= h($dev['portfolio'], ENT_QUOTES, 'UTF-8') ?>" class="footer-link" target="_blank" rel="noopener noreferrer"><?= h($dev['name'], ENT_QUOTES, 'UTF-8') ?></a>
    </span>
    <nav class="site-footer-nav">
        <a href="/about"
           class="footer-link"
           hx-get="/about"
           hx-target="#mainContent"
           hx-swap="outerHTML"
           hx-push-url="true">About</a>
        <a href="<?= h($repo['source'], ENT_QUOTES, 'UTF-8') ?>" class="footer-link" target="_blank" rel="noopener noreferrer">Github ↗</a>
        <a href="<?= h($dev['portfolio'], ENT_QUOTES, 'UTF-8') ?>" class="footer-link" target="_blank" rel="noopener noreferrer">Portfolio ↗</a>
        <a href="<?= h($dev['support'], ENT_QUOTES, 'UTF-8') ?>" class="footer-link" target="_blank" rel="noopener noreferrer">Support ↗</a>
    </nav>
</footer>

<!-- ── Confirmation Modal ──────────────────────────────────────────── -->
<div class="modal-overlay" id="confirmationModal" style="z-index: 600;">
    <div class="modal-box" style="max-width:380px;">
        <div class="modal-header">
            <span class="modal-title" id="confirmationTitle">Confirm Action</span>
            <button class="modal-close js-close-confirmation" type="button">
                <i data-lucide="x" style="width:14px;height:14px;"></i>
            </button>
        </div>
        <div id="confirmationMessage" style="font-size:13px;color:var(--text-secondary);line-height:1.6;margin-bottom:20px;"></div>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost btn-md js-close-confirmation" style="flex:1;">Cancel</button>
            <button type="button" class="btn btn-danger btn-md" id="confirmationOkBtn" style="flex:1;">Confirm</button>
        </div>
    </div>
</div>

<!-- ── Config Modal ────────────────────────────────────────────────── -->
<div class="modal-overlay" id="configModal">
    <div class="modal-box">
        <!-- Content loaded dynamically by HTMX -->
    </div>
</div>

<!-- ── History Modal ──────────────────────────────────────────────── -->
<div class="modal-overlay" id="historyModal">
    <div class="modal-box" style="max-width:440px;">
        <div class="modal-header">
            <span class="modal-title">Rotation Details</span>
            <button class="modal-close js-close-history-modal" type="button">
                <i data-lucide="x" style="width:14px;height:14px;"></i>
            </button>
        </div>
        <div class="history-detail-grid">
            <div class="history-detail-label">Secret</div>
            <div class="history-detail-value" id="historyDetailSecret">--</div>
            <div class="history-detail-label">Service</div>
            <div class="history-detail-value" id="historyDetailService">--</div>
            <div class="history-detail-label">Triggered By</div>
            <div class="history-detail-value" id="historyDetailTrigger">--</div>
            <div class="history-detail-label">Rotated At</div>
            <div class="history-detail-value" id="historyDetailTime">--</div>
        </div>
        <div class="history-values-block">
            <div class="history-values-label">
                Old Value <span class="history-values-hint">(before rotation)</span>
                <button type="button" class="btn-icon js-copy-history-value" data-target="historyDetailOldValue" title="Copy old value" style="margin-left:6px;">
                    <i data-lucide="copy" style="width:11px;height:11px;"></i>
                </button>
            </div>
            <pre class="history-values-pre" id="historyDetailOldValue">--</pre>
        </div>
        <div class="history-values-block">
            <div class="history-values-label">
                New Value <span class="history-values-hint" id="historyDetailNewValueHint"></span>
                <button type="button" class="btn-icon js-copy-history-value" data-target="historyDetailNewValue" title="Copy new value" style="margin-left:6px;">
                    <i data-lucide="copy" style="width:11px;height:11px;"></i>
                </button>
            </div>
            <pre class="history-values-pre" id="historyDetailNewValue">--</pre>
            <button type="button" id="historyShowCurrentBtn"
                    class="btn btn-ghost btn-sm"
                    style="display:none;margin-top:6px;font-size:11px;">
                <i data-lucide="radio-tower" style="width:11px;height:11px;"></i>
                Show current value
            </button>
        </div>
        <div class="modal-actions" style="margin-top:16px;">
            <button type="button" class="btn btn-ghost btn-md js-close-history-modal" style="flex:1;">Close</button>
            <button type="button" class="btn btn-danger btn-md" id="historyRollbackBtn"
                    data-secret-name=""
                    data-service-id=""
                    data-history-id=""
                    style="flex:1;">
                <i data-lucide="undo-2" style="width:13px;height:13px;"></i>
                Rollback to this
            </button>
        </div>
    </div>
</div>

<!-- ── Group Management Modal ──────────────────────────────────────── -->
<div class="modal-overlay" id="groupModal">
    <div class="modal-box" style="max-width:500px;">
        <div class="modal-header">
            <span class="modal-title" id="groupModalTitle">Manage Group</span>
            <button class="modal-close js-close-group-modal" type="button">
                <i data-lucide="x" style="width:14px;height:14px;"></i>
            </button>
        </div>
        <form id="groupModalForm">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" id="groupModalAction" value="create"><!-- create, edit, delete -->
            
            <!-- Create/Edit form -->
            <div id="groupFormContent">
                <div class="form-group">
                    <label class="form-label">Group Name</label>
                    <input type="text" name="group_name" id="groupNameInput" class="form-control" placeholder="e.g., Production, Staging" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Services in this group</label>
                    <div class="form-hint" style="margin-bottom:8px;">Choose one or more services to include in this group.</div>
                    <div id="groupServicesList" style="max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px;"></div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-ghost btn-md js-close-group-modal" style="flex:1;">Cancel</button>
                <button type="submit" class="btn btn-primary btn-md" style="flex:1;">Save</button>
            </div>
        </form>
    </div>
</div>



</body>
</html>
