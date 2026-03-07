<div id="mainContent" class="main-content" data-service-id="<?= htmlspecialchars((string)$serviceId, ENT_QUOTES, 'UTF-8') ?>" data-section="<?= htmlspecialchars((string)($section ?? 'secrets'), ENT_QUOTES, 'UTF-8') ?>" data-view-title="<?= htmlspecialchars($viewTitle, ENT_QUOTES, 'UTF-8') ?>" data-cache-fetched-at="<?= (int)($cacheFetchedAt ?? 0) ?>">

    <?php
        $currentSection = $section ?? 'secrets';
        $managedCount = isset($managed) && is_array($managed) ? count($managed) : 0;
        $serviceCount = isset($serviceCount) ? (int)$serviceCount : (isset($services) && is_array($services) ? count($services) : 0);
        $historyRows = isset($recentHistory) && is_array($recentHistory) ? $recentHistory : [];
        $rotations24h = 0;
        $nowTs = time();
        foreach ($historyRows as $row) {
            $rotatedAtTs = strtotime((string)($row['rotated_at'] ?? ''));
            if ($rotatedAtTs !== false && ($nowTs - $rotatedAtTs) <= 86400) {
                $rotations24h++;
            }
        }
    ?>

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <h1><?= htmlspecialchars($viewTitle) ?></h1>
            <p>
                <?php if ($currentSection === 'overview'): ?>
                    Quick status, helpful tips, and recent rotation activity.
                <?php else: ?>
                    Manage and rotate Railway environment secrets
                <?php endif; ?>
            </p>
        </div>
        <?php if ($currentSection === 'secrets'): ?>
        <div class="flex items-center gap-6">
            <input type="search" id="secretSearch" class="form-control form-control-compact" placeholder="Search secrets..." aria-label="Search secrets">
            <label class="toggle-container" title="Show/Hide variables injected by Railway (prefixed with RAILWAY_)">
                <input type="checkbox" id="toggleRailway">
                <span class="toggle-label">Railway Variables</span>
                <div class="toggle-switch"></div>
            </label>
            <button class="btn btn-ghost btn-sm"
                    type="button"
                    hx-get="/api/secrets-table?serviceId=<?= urlencode((string)($serviceId ?? '')) ?>"
                    hx-target="#secrets-table-body"
                    hx-swap="innerHTML"
                    hx-indicator="#refreshIndicator">
                <i data-lucide="refresh-cw" style="width:13px;height:13px;" id="refreshIndicator" class="refresh-indicator"></i>
                Refresh
            </button>
            <button class="btn btn-ghost btn-sm"
                    type="button"
                    id="syncCacheBtn"
                    data-service-id="<?= htmlspecialchars((string)$serviceId, ENT_QUOTES, 'UTF-8') ?>">
                <i data-lucide="database-zap" style="width:13px;height:13px;"></i>
                Sync Cache
            </button>
            <span class="cache-status-text" id="cacheStatusText">Cache: --</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Page Body -->
    <div class="page-body">

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i data-lucide="alert-circle" style="width:14px;height:14px;"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($currentSection === 'overview'): ?>
        <div class="overview-grid">
            <div class="panel overview-card">
                <div class="overview-card-label">Managed Secrets</div>
                <div class="overview-card-value"><?= (int)$managedCount ?></div>
                <div class="overview-card-sub">Secrets with saved rotation config</div>
            </div>
            <div class="panel overview-card">
                <div class="overview-card-label">Services</div>
                <div class="overview-card-value"><?= (int)$serviceCount ?></div>
                <div class="overview-card-sub">Railway services in this project</div>
            </div>
            <div class="panel overview-card">
                <div class="overview-card-label">Rotations (24h)</div>
                <div class="overview-card-value"><?= (int)$rotations24h ?></div>
                <div class="overview-card-sub">Recent updates in the last day</div>
            </div>
        </div>

        <div class="overview-columns">
            <div class="panel overview-tips-panel">
                <h3 class="overview-panel-title">Quick Tips</h3>
                <ul class="overview-list">
                    <li>Use <strong>Create Group</strong> to organize services before editing secrets.</li>
                    <li>Keep <strong>Rotation Interval</strong> to <code>0</code> for manual-only keys.</li>
                    <li>Use <strong>Sync Cache</strong> when Railway changes are not visible yet.</li>
                    <li>Open <strong>Rotation History</strong> to validate scheduled rotations.</li>
                </ul>
            </div>
            <div class="panel overview-activity-panel">
                <div class="history-header" style="margin-bottom:8px;">
                    <div>
                        <h3 class="overview-panel-title">Overall Activity</h3>
                        <p>Latest 5 rotations across all scopes.</p>
                    </div>
                    <a class="btn btn-ghost btn-sm" href="/?section=history" hx-get="/?section=history" hx-target="#mainContent" hx-swap="outerHTML" hx-push-url="true" hx-indicator="#mainContentLoading">
                        <i data-lucide="history" style="width:13px;height:13px;"></i>
                        View all
                    </a>
                </div>
                <table class="data-table history-table">
                    <colgroup>
                        <col style="width: 52%;">
                        <col style="width: 18%;">
                        <col style="width: 30%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Secret</th>
                            <th></th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $recentHistory = array_slice($historyRows, 0, 5); ?>
                        <?php include __DIR__ . '/rotation-history-body.php'; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($currentSection !== 'history'): ?>
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
        <?php else: ?>
        <div class="panel history-panel">
            <div class="history-header">
                <div>
                    <h3>Rotation History</h3>
                    <p>All recorded secret rotations. Click <strong>Inspect</strong> to view old &amp; new values.</p>
                </div>
                <button class="btn btn-ghost btn-sm"
                        type="button"
                        hx-get="/api/rotation-history?serviceId=<?= urlencode((string)$serviceId) ?>"
                        hx-target="#rotation-history-body"
                        hx-swap="innerHTML"
                        hx-indicator="#historyRefreshIndicator">
                    <i data-lucide="refresh-cw" id="historyRefreshIndicator" class="refresh-indicator" style="width:13px;height:13px;"></i>
                    Refresh
                </button>
            </div>
            <table class="data-table history-table">
                <colgroup>
                    <col style="width: 52%;">
                    <col style="width: 16%;">
                    <col style="width: 32%;">
                </colgroup>
                <thead>
                    <tr>
                        <th>Secret</th>
                        <th></th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody id="rotation-history-body">
                    <?php include __DIR__ . '/rotation-history-body.php'; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div><!-- /page-body -->
</div><!-- /main-content -->
