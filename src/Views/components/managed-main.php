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
    $interval = (int)($row['interval_days'] ?? 0);
    if ($interval > 0) {
        $unitConf = getUnitConfig($row['interval_unit'] ?? 'day');
        if (empty($row['last_auto_rotated'])) {
            $dueCount++;
        } else {
            $lastTs = strtotime((string)$row['last_auto_rotated']);
            if ($lastTs !== false && ((time() - $lastTs) / $unitConf['divisor']) >= $interval) {
                $dueCount++;
            }
        }
    }
}
?>
<div id="mainContent" class="main-content"
     data-service-id=""
     data-section="managed"
     data-view-title="<?= htmlspecialchars($viewTitle) ?>"
     data-cache-fetched-at="0">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <h1><?= htmlspecialchars($viewTitle) ?></h1>
            <p>All rotation-configured secrets across every scope. <?= count($allManaged) ?> managed<?= $dueCount > 0 ? ', <strong>' . $dueCount . ' overdue</strong>' : '' ?>.</p>
        </div>
        <?php if ($dueCount > 0): ?>
        <div class="flex items-center gap-6">
            <button class="btn btn-primary btn-sm" type="button" id="rotateDueBtn">
                <i data-lucide="rotate-cw" style="width:13px;height:13px;"></i>
                Rotate Due (<?= $dueCount ?>)
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Page Body -->
    <div class="page-body">
        <?php if (empty($allManaged)): ?>
        <div class="empty-state" style="padding: 60px 0;">
            <i data-lucide="shield-off" style="width:36px;height:36px;"></i>
            <p>No managed secrets yet. Open a service, click the ⚙ icon on any variable, and save a rotation config.</p>
        </div>
        <?php else: ?>
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
                <?php foreach ($allManaged as $row):
                    $interval     = (int)($row['interval_days'] ?? 0);
                    $unitSuffix   = ['minute' => 'm', 'hour' => 'h', 'day' => 'd'][$row['interval_unit'] ?? 'day'] ?? 'd';
                    $isManualOnly = $interval === 0;
                    $unitConf     = getUnitConfig($row['interval_unit'] ?? 'day');
                    $isDue        = false;
                    if (!$isManualOnly) {
                        if (empty($row['last_auto_rotated'])) {
                            $isDue = true;
                        } else {
                            $lastTs = strtotime((string)$row['last_auto_rotated']);
                            if ($lastTs !== false && ((time() - $lastTs) / $unitConf['divisor']) >= $interval) {
                                $isDue = true;
                            }
                        }
                    }
                ?>
                    <tr>
                        <td>
                            <div class="key-name">
                                <i data-lucide="shield-check" class="key-icon managed" style="width:13px;height:13px;"></i>
                                <span class="key-name-text"><?= htmlspecialchars($row['secret_name']) ?></span>
                            </div>
                            <div style="margin-top:3px;">
                                <span class="badge badge-manual"><?= htmlspecialchars($row['service_name']) ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="config-text"><?= (int)$row['length'] ?> · <?= htmlspecialchars($row['encoding']) ?></span>
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
                            <span class="js-relative-time" data-timestamp="<?= htmlspecialchars((string)$row['last_rotated'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars((string)$row['last_rotated']) ?>
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
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
