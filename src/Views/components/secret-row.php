<?php
/**
 * Secret row component
 * 
 * Required variables:
 * @var string $name - Secret name
 * @var array|null $config - Configuration array
 * @var string $serviceId - Current service ID
 * @var string $csrfToken - CSRF token
 */

$keyId = ($serviceId ?: 'global') . ':' . $name;
$isManaged = (bool)$config;
$isRailway = strpos($name, 'RAILWAY_') === 0;
$rowId = 'secret-' . md5($keyId);
?>
<tr class="secret-tr" 
    id="<?= h($rowId) ?>"
    data-is-railway="<?= $isRailway ? '1' : '0' ?>"
    data-is-managed="<?= $isManaged ? '1' : '0' ?>"
    data-key-name="<?= h($name, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Secret Name + Value -->
    <td>
        <div class="key-name">
            <i data-lucide="<?= $isManaged ? 'shield-check' : 'shield-off' ?>"
               class="key-icon <?= $isManaged ? 'managed' : 'unmanaged' ?>"
               style="width:13px;height:13px;"></i>
            <span class="key-name-text"><?= h($name) ?></span>
        </div>
        <div class="secret-row">
            <span class="secret-value"
                  data-secret-name="<?= h($name, ENT_QUOTES, 'UTF-8') ?>"
                  data-service-id="<?= h((string)($serviceId ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                  title="Click eye to reveal">••••••••••••</span>
            <button class="btn-icon js-toggle-secret" type="button" title="Toggle visibility"
                    data-secret-name="<?= h($name, ENT_QUOTES, 'UTF-8') ?>"
                    data-service-id="<?= h((string)($serviceId ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <i data-lucide="eye" style="width:12px;height:12px;"></i>
            </button>
            <button class="btn-icon js-copy-secret" type="button"
                    data-secret-name="<?= h($name, ENT_QUOTES, 'UTF-8') ?>"
                    data-service-id="<?= h((string)($serviceId ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    title="Copy">
                <i data-lucide="copy" style="width:12px;height:12px;"></i>
            </button>
        </div>
    </td>

    <!-- Rotation (config + schedule merged) -->
    <td>
        <?php
        $unitSuffix = ['minute' => 'm', 'hour' => 'h', 'day' => 'd'][$config['interval_unit'] ?? 'day'] ?? 'd';
        ?>
        <?php if ($isManaged): ?>
            <div class="rotation-cell">
                <span class="config-text"><?= (int)$config['length'] ?> chars · <?= h($config['encoding']) ?></span>
                <?php if ($config['interval_days'] > 0): ?>
                    <span class="badge badge-schedule">
                        <i data-lucide="clock" style="width:10px;height:10px;"></i>
                        Every <?= (int)$config['interval_days'] ?><?= $unitSuffix ?>
                    </span>
                <?php else: ?>
                    <span class="badge badge-manual">On demand</span>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <button class="config-not-configured js-open-config-modal" type="button"
                    title="Set up rotation"
                    hx-get="/api/config-form?name=<?= urlencode($name) ?>&serviceId=<?= urlencode((string)($serviceId ?? '')) ?>"
                    hx-target="#configModal .modal-box"
                    hx-swap="innerHTML">
                <i data-lucide="plus-circle" style="width:10px;height:10px;"></i>
                Set up rotation
            </button>
        <?php endif; ?>
    </td>

    <!-- Actions -->
    <td>
        <div class="actions-cell">
            <?php if ($isManaged): ?>
            <button class="btn-icon js-open-config-modal"
                    type="button"
                    title="Rotate now"
                    hx-get="/api/rotate-form?name=<?= urlencode($name) ?>&serviceId=<?= urlencode((string)($serviceId ?? '')) ?>"
                    hx-target="#configModal .modal-box"
                    hx-swap="innerHTML">
                <i data-lucide="rotate-cw" style="width:13px;height:13px;"></i>
            </button>
            <button class="btn-icon js-rollback-btn"
                    type="button"
                    title="Rollback last rotation"
                    data-secret-name="<?= h($name, ENT_QUOTES, 'UTF-8') ?>"
                    data-service-id="<?= h((string)($serviceId ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <i data-lucide="undo-2" style="width:13px;height:13px;"></i>
            </button>
            <?php endif; ?>
            <button class="btn-icon js-open-config-modal"
                    type="button"
                    title="<?= $isManaged ? 'Edit config' : 'Set up rotation' ?>"
                    hx-get="/api/config-form?name=<?= urlencode($name) ?>&serviceId=<?= urlencode((string)($serviceId ?? '')) ?>"
                    hx-target="#configModal .modal-box"
                    hx-swap="innerHTML">
                <i data-lucide="settings-2" style="width:13px;height:13px;"></i>
            </button>
        </div>
    </td>
</tr>
