<?php
/**
 * Secret row component
 * 
 * Required variables:
 * @var string $name - Secret name
 * @var string $value - Secret value
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
    id="<?= htmlspecialchars($rowId) ?>"
    data-is-railway="<?= $isRailway ? '1' : '0' ?>"
    data-key-name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Secret Name + Value -->
    <td>
        <div class="key-name">
            <i data-lucide="<?= $isManaged ? 'shield-check' : 'shield-off' ?>"
               class="key-icon <?= $isManaged ? 'managed' : 'unmanaged' ?>"
               style="width:13px;height:13px;"></i>
            <span class="key-name-text"><?= htmlspecialchars($name) ?></span>
        </div>
        <div class="secret-row">
            <span class="secret-value"
                  data-value="<?= htmlspecialchars($value) ?>"
                  title="Click to view">••••••••••••</span>
            <button class="btn-icon js-toggle-secret" type="button" title="Toggle visibility">
                <i data-lucide="eye" style="width:12px;height:12px;"></i>
            </button>
            <button class="btn-icon js-copy-secret" type="button" 
                    data-secret-value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" 
                    title="Copy">
                <i data-lucide="copy" style="width:12px;height:12px;"></i>
            </button>
        </div>
    </td>

    <!-- Config -->
    <td>
        <?php if ($isManaged): ?>
            <span class="config-text"><?= (int)$config['length'] ?> chars · <?= htmlspecialchars($config['encoding']) ?></span>
        <?php else: ?>
            <span class="config-text empty">—</span>
        <?php endif; ?>
    </td>

    <!-- Schedule -->
    <td>
        <?php if ($isManaged && $config['interval_days'] > 0): ?>
            <span class="badge badge-schedule">
                <i data-lucide="clock" style="width:10px;height:10px;"></i>
                Every <?= (int)$config['interval_days'] ?><?= htmlspecialchars($timeConfig['suffix'] ?? 'd') ?>
            </span>
        <?php elseif ($isManaged): ?>
            <span class="badge badge-manual">Manual</span>
        <?php else: ?>
            <span class="config-text empty">—</span>
        <?php endif; ?>
    </td>

    <!-- Actions -->
    <td>
        <div class="actions-cell">
            <button class="btn-icon js-open-config-modal" 
                    type="button" 
                    title="Rotate & Configure"
                    hx-get="/api/config-form?name=<?= urlencode($name) ?>&serviceId=<?= urlencode((string)($serviceId ?? '')) ?>"
                    hx-target="#configModal .modal-box"
                    hx-swap="innerHTML">
                <i data-lucide="rotate-cw" style="width:13px;height:13px;"></i>
            </button>
        </div>
    </td>
</tr>
