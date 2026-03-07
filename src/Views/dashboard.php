<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title><?= htmlspecialchars($viewTitle) ?> — Railway Secrets</title>
    <link rel="stylesheet" href="/style.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="https://unpkg.com/htmx.org@2.0.4" defer></script>
    <script src="/js/dashboard.js" defer></script>
</head>
<body data-current-service-id="<?= htmlspecialchars((string)$serviceId, ENT_QUOTES, 'UTF-8') ?>" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<div class="app-layout">

    <!-- ── Sidebar ────────────────────────────────────────────────── -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="/favicon.svg" alt="" class="brand-mark" width="16" height="16">
            Railway Secrets
        </div>

        <div class="sidebar-section-label">Project</div>
        <nav>
                <a href="/" class="nav-link js-scope-nav <?= !$serviceId ? 'active' : '' ?>"
               hx-get="/"
               hx-target="#mainContent"
               hx-swap="outerHTML"
               hx-push-url="true"
                    hx-indicator="#mainContentLoading"
               data-service-id="">
                <i data-lucide="layers" style="width:14px;height:14px;"></i>
                Global Variables
            </a>
        </nav>

        <?php if (!empty($services)): ?>
        <div class="sidebar-section-label" style="margin-top:12px;">Services</div>
        <nav style="overflow-y:auto;flex:1;">
            <?php foreach ($services as $svc): ?>
                     <a href="/?serviceId=<?= $svc['id'] ?>" class="nav-link js-scope-nav <?= $serviceId === $svc['id'] ? 'active' : '' ?>"
                   hx-get="/?serviceId=<?= urlencode($svc['id']) ?>"
                   hx-target="#mainContent"
                   hx-swap="outerHTML"
                   hx-push-url="true"
                         hx-indicator="#mainContentLoading"
                   data-service-id="<?= htmlspecialchars($svc['id'], ENT_QUOTES, 'UTF-8') ?>">
                    <i data-lucide="box" style="width:14px;height:14px;"></i>
                    <?= htmlspecialchars($svc['name']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <div class="sidebar-spacer"></div>
        <div class="sidebar-footer">
            <a href="/docs" class="nav-link">
                <i data-lucide="book-open" style="width:14px;height:14px;"></i>
                Docs
            </a>
            <form method="POST" action="/logout" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <button type="submit" class="nav-link nav-link-btn danger">
                    <i data-lucide="log-out" style="width:14px;height:14px;"></i>
                    Logout
                </button>
            </form>
        </div>
    </aside>

    <!-- ── Main ───────────────────────────────────────────────────── -->
    <?php include __DIR__ . '/components/dashboard-main.php'; ?>
    <div id="mainContentLoading" class="main-content-loading htmx-indicator" aria-hidden="true">
        <div class="main-content-loading-inner">
            <i data-lucide="loader-circle" class="main-loading-spinner" style="width:18px;height:18px;"></i>
            <span>Loading scope...</span>
        </div>
    </div>
</div><!-- /app-layout -->

<!-- ── Confirm Dialog ────────────────────────────────────────────── -->
<div class="modal-overlay" id="confirmModal" style="z-index: 600;">
    <div class="modal-box" style="max-width:340px;">
        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;">
            <div style="flex-shrink:0;width:34px;height:34px;border-radius:8px;background:var(--danger-bg);border:1px solid rgba(239,68,68,0.2);display:flex;align-items:center;justify-content:center;margin-top:1px;">
                <i data-lucide="alert-triangle" id="confirmIcon" style="width:16px;height:16px;color:var(--danger);"></i>
            </div>
            <div>
                <div class="modal-title" id="confirmTitle" style="margin-bottom:4px;">Are you sure?</div>
                <div id="confirmMessage" style="font-size:12px;color:var(--text-secondary);line-height:1.5;"></div>
            </div>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-ghost btn-md" style="flex:1;" id="confirmCancelBtn">Cancel</button>
            <button class="btn btn-danger btn-md" style="flex:1;" id="confirmOkBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- ── Config Modal ───────────────────────────────────────────────── -->
<div class="modal-overlay" id="configModal">
    <div class="modal-box">
        <!-- Content loaded dynamically by HTMX -->
    </div>
</div>
</body>
</html>
