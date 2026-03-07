<div id="mainContent" class="main-content" data-service-id="<?= htmlspecialchars((string)$serviceId, ENT_QUOTES, 'UTF-8') ?>" data-view-title="<?= htmlspecialchars($viewTitle, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <h1><?= htmlspecialchars($viewTitle) ?></h1>
            <p>Manage and rotate Railway environment secrets</p>
        </div>
        <div class="flex items-center gap-6">
            <input type="search" id="secretSearch" class="form-control form-control-compact" placeholder="Search secrets..." aria-label="Search secrets">
            <label class="toggle-container" title="Show/Hide variables injected by Railway (prefixed with RAILWAY_)">
                <input type="checkbox" id="toggleRailway">
                <span class="toggle-label">Railway</span>
                <div class="toggle-switch"></div>
            </label>
            <button class="btn btn-ghost btn-sm"
                    type="button"
                    hx-get="/api/secrets-table?serviceId=<?= urlencode($serviceId) ?>"
                    hx-target="#secrets-table-body"
                    hx-swap="innerHTML"
                    hx-indicator="#refreshIndicator">
                <i data-lucide="refresh-cw" style="width:13px;height:13px;" id="refreshIndicator" class="refresh-indicator"></i>
                Refresh
            </button>
        </div>
    </div>

    <!-- Page Body -->
    <div class="page-body">

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i data-lucide="alert-circle" style="width:14px;height:14px;"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="panel">
            <table class="data-table">
                <colgroup>
                    <col style="width: 52%;">
                    <col style="width: 19%;">
                    <col style="width: 21%;">
                    <col style="width: 8%;">
                </colgroup>
                <thead>
                    <tr>
                        <th>Secret</th>
                        <th>Config</th>
                        <th>Schedule</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="secrets-table-body">
                    <?php include __DIR__ . '/secrets-table-body.php'; ?>
                </tbody>
            </table>
        </div>

    </div><!-- /page-body -->
</div><!-- /main-content -->
