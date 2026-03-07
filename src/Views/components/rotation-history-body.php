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
        $serviceId = (string)($item['service_id'] ?? '');
        $serviceLabel = $serviceId !== '' ? ($serviceNameMap[$serviceId] ?? $serviceId) : 'Global Variables';
        $rotatedAt = (string)($item['rotated_at'] ?? '');
        ?>
        <tr class="history-row js-history-row"
            data-history-secret="<?= htmlspecialchars((string)$item['secret_name'], ENT_QUOTES, 'UTF-8') ?>"
            data-history-service="<?= htmlspecialchars($serviceLabel, ENT_QUOTES, 'UTF-8') ?>"
            data-history-rotated="<?= htmlspecialchars($rotatedAt, ENT_QUOTES, 'UTF-8') ?>">
            <td>
                <span class="history-secret"><?= htmlspecialchars((string)$item['secret_name']) ?></span>
            </td>
            <td>
                <span class="history-service"><?= htmlspecialchars($serviceLabel) ?></span>
            </td>
            <td>
                <span class="history-time"><?= htmlspecialchars($rotatedAt) ?></span>
            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
