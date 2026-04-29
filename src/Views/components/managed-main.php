<?php
/**
 * Managed Secrets Overview Component
 *
 * @var string $viewTitle
 * @var string $csrfToken
 * @var array  $allManaged
 */

// Pre-compute how many scheduled secrets are currently due
$dueCount = 0;
foreach ($allManaged as $row) {
    if ((int)($row['interval_days'] ?? 0) > 0) {
        $nextAt = !empty($row['next_rotation_at']) ? strtotime((string)$row['next_rotation_at']) : null;
        if ($nextAt !== null && $nextAt <= time()) {
            $dueCount++;
        }
    }
}

$syncGroupOptions = [];
foreach ($allManaged as $row) {
    $group = trim((string)($row['sync_group'] ?? ''));
    if ($group !== '') {
        $syncGroupOptions[$group] = true;
    }
}
$syncGroupOptions = array_keys($syncGroupOptions);
sort($syncGroupOptions, SORT_NATURAL | SORT_FLAG_CASE);
?>
<div id="mainContent" class="main-content"
     data-service-id=""
     data-section="managed"
     data-view-title="<?= h($viewTitle) ?>"
     data-cache-fetched-at="0">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <h1><?= h($viewTitle) ?></h1>
            <p>All rotation-configured secrets across every scope. <?= count($allManaged) ?> managed<?= $dueCount > 0 ? ', <strong>' . $dueCount . ' overdue</strong>' : '' ?>.</p>
        </div>
        <div class="flex items-center gap-6">
            <?php if (!empty($syncGroupOptions)): ?>
            <label class="form-hint" for="managedGroupFilter" style="margin:0;color:var(--text-muted);">Group</label>
            <select id="managedGroupFilter" class="form-control form-control-compact" style="min-width:180px;">
                <option value="">All groups</option>
                <option value="__none__">No group</option>
                <?php foreach ($syncGroupOptions as $group): ?>
                    <option value="<?= h($group, ENT_QUOTES, 'UTF-8') ?>"><?= h($group) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($dueCount > 0): ?>
            <button class="btn btn-primary btn-sm" type="button" id="rotateDueBtn">
                <i data-lucide="rotate-cw" style="width:13px;height:13px;"></i>
                Rotate Due (<?= $dueCount ?>)
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Page Body -->
    <div class="page-body">
        <div class="panel">
            <table class="data-table">
                <colgroup>
                    <col style="width: 30%;">
                    <col style="width: 18%;">
                    <col style="width: 18%;">
                    <col style="width: 22%;">
                    <col style="width: 12%;">
                </colgroup>
                <thead>
                    <tr>
                        <th>Secret</th>
                        <th>Config</th>
                        <th>Last Rotated</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($allManaged)): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <i data-lucide="shield-off" style="width:24px;height:24px;"></i>
                                <p>No managed secrets yet. Open a service, click the ⚙ icon on any variable, and save a rotation config.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                <?php foreach ($allManaged as $row):
                    $interval     = (int)($row['interval_days'] ?? 0);
                    $unitSuffix   = ['minute' => 'm', 'hour' => 'h', 'day' => 'd'][$row['interval_unit'] ?? 'day'] ?? 'd';
                    $isManualOnly = $interval === 0;
                    $unitConf     = getUnitConfig($row['interval_unit'] ?? 'day');
                    $isDue = !$isManualOnly
                        && !empty($row['next_rotation_at'])
                        && strtotime((string)$row['next_rotation_at']) <= time();
                    $syncGroupValue = trim((string)($row['sync_group'] ?? ''));
                ?>
                    <tr class="managed-row" data-sync-group="<?= h($syncGroupValue !== '' ? $syncGroupValue : '__none__', ENT_QUOTES, 'UTF-8') ?>">
                        <td>
                            <div class="key-name">
                                <i data-lucide="shield-check" class="key-icon managed" style="width:13px;height:13px;"></i>
                                <span class="key-name-text"><?= h($row['secret_name']) ?></span>
                            </div>
                            <div style="margin-top:3px;">
                                <span class="badge badge-manual"><?= h($row['service_name']) ?></span>
                                <?php if (!empty($row['sync_group'])): ?>
                                    <span class="badge badge-schedule" style="margin-left:4px;">
                                        <i data-lucide="link-2" style="width:10px;height:10px;"></i>
                                        <?= h((string)$row['sync_group']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="config-text"><?= (int)$row['length'] ?> chars · <?= h($row['encoding']) ?></span>
                            <?php if (!$isManualOnly): ?>
                            <div style="margin-top:3px;">
                                <span class="badge badge-schedule">
                                    <i data-lucide="clock" style="width:10px;height:10px;"></i>
                                    Every <?= $interval ?><?= $unitSuffix ?>
                                </span>
                            </div>
                            <?php else: ?>
                            <div style="margin-top:3px;"><span class="badge badge-manual">On demand</span></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['last_rotated'])): ?>
                            <span class="js-relative-time" data-timestamp="<?= h((string)$row['last_rotated'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= h((string)$row['last_rotated']) ?>
                            </span>
                            <?php else: ?>
                            <span style="color:var(--text-muted);font-size:12px;">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isManualOnly): ?>
                            <span class="badge badge-manual">Manual only</span>
                            <?php elseif ($isDue): ?>
                            <span class="badge" style="background:rgba(239,68,68,0.12);color:#f87171;border:1px solid rgba(239,68,68,0.2);">
                                <i data-lucide="alert-circle" style="width:10px;height:10px;"></i>
                                Overdue
                            </span>
                            <?php else: ?>
                            <span class="badge" style="background:rgba(34,197,94,0.08);color:#4ade80;border:1px solid rgba(34,197,94,0.15);">
                                <i data-lucide="check-circle" style="width:10px;height:10px;"></i>
                                On schedule
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions-cell">
                                <?php if (!empty($row['sync_group'])): ?>
                                <button class="btn-icon js-rotate-sync-group"
                                        type="button"
                                        title="Rotate sync group"
                                        data-sync-group="<?= h((string)$row['sync_group'], ENT_QUOTES, 'UTF-8') ?>">
                                    <i data-lucide="link-2" style="width:13px;height:13px;"></i>
                                </button>
                                <?php endif; ?>
                                <button class="btn-icon js-open-config-modal"
                                        type="button"
                                        title="Rotate now"
                                        hx-get="/api/rotate-form?name=<?= urlencode($row['secret_name']) ?>&serviceId=<?= urlencode((string)($row['service_id'] ?? '')) ?>"
                                        hx-target="#configModal .modal-box"
                                        hx-swap="innerHTML">
                                    <i data-lucide="rotate-cw" style="width:13px;height:13px;"></i>
                                </button>
                                <button class="btn-icon js-open-config-modal"
                                        type="button"
                                        title="Edit config"
                                        hx-get="/api/config-form?name=<?= urlencode($row['secret_name']) ?>&serviceId=<?= urlencode((string)($row['service_id'] ?? '')) ?>"
                                        hx-target="#configModal .modal-box"
                                        hx-swap="innerHTML">
                                    <i data-lucide="settings-2" style="width:13px;height:13px;"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
