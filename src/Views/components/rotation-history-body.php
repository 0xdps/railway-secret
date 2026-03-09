<?php
/**
 * Rotation history table body
 *
 * Required variables:
 * @var array $recentHistory
 * @var array $serviceNameMap
 */
?>
<?php if (empty($recentHistory)): ?>
    <tr>
        <td colspan="3">
            <div class="empty-state" style="padding: 24px 20px;">
                <i data-lucide="history" style="width:24px;height:24px;"></i>
                <p>No rotation history yet.</p>
            </div>
        </td>
    </tr>
<?php else: ?>
    <?php foreach ($recentHistory as $item): ?>
        <?php
        $historyId = (int)($item['id'] ?? 0);
        $secretName = (string)($item['secret_name'] ?? '');
        $serviceId    = (string)($item['service_id'] ?? '');
        $serviceLabel = $serviceId !== ''
            ? ($item['service_name'] ?? ($serviceNameMap[$serviceId] ?? $serviceId))
            : 'Global';
        $rotatedAt = (string)($item['rotated_at'] ?? '');
        $triggerType = (string)($item['trigger_type'] ?? 'manual');
        $isAuto     = $triggerType === 'auto';
        $isRollback = $triggerType === 'rollback';
        $badgeClass = $isAuto ? 'trigger-auto' : ($isRollback ? 'trigger-rollback' : 'trigger-manual');
        $badgeLabel = $isAuto ? 'auto' : ($isRollback ? 'rollback' : 'manual');
        ?>
        <tr class="history-row js-history-row" data-history-id="<?= (int)$historyId ?>">
            <td>
                <div class="history-entry">
                    <span class="history-secret-name"><?= htmlspecialchars($secretName) ?></span>
                    <span class="history-service-badge"><?= htmlspecialchars($serviceLabel) ?></span>
                    <span class="history-trigger-badge <?= $badgeClass ?>">
                        <?= $badgeLabel ?>
                    </span>
                </div>
            </td>
            <td>
                <button type="button"
                    class="btn-inspect js-history-values-btn"
                    data-history-id="<?= (int)$historyId ?>">
                    <i data-lucide="key-round" style="width:11px;height:11px;"></i>
                    Inspect
                </button>
            </td>
            <td>
                <span class="history-time js-relative-time" data-timestamp="<?= htmlspecialchars($rotatedAt) ?>"><?= htmlspecialchars($rotatedAt) ?></span>
            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
