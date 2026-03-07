<?php
/**
 * Configuration modal form content
 * 
 * Required variables:
 * @var string $secretName - Secret name being configured
 * @var array|null $config - Existing configuration or null
 * @var string $serviceId - Current service ID
 * @var string $csrfToken - CSRF token
 */
?>
<div class="modal-header">
    <span class="modal-title">Rotate & Configure: <?= htmlspecialchars($secretName) ?></span>
    <button class="modal-close js-close-config-modal" type="button">
        <i data-lucide="x" style="width:14px;height:14px;"></i>
    </button>
</div>

<form hx-post="/api/config" 
      hx-target="#secrets-table-body" 
    hx-swap="innerHTML"
    hx-indicator="#configSavingIndicator">
    
    <input type="hidden" name="name" value="<?= htmlspecialchars($secretName) ?>">
    <input type="hidden" name="serviceId" value="<?= htmlspecialchars($serviceId) ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Length</label>
            <select name="length" class="form-control">
                <option value="16" <?= ($config['length'] ?? 32) == 16 ? 'selected' : '' ?>>16 chars</option>
                <option value="32" <?= ($config['length'] ?? 32) == 32 ? 'selected' : '' ?>>32 chars</option>
                <option value="64" <?= ($config['length'] ?? 32) == 64 ? 'selected' : '' ?>>64 chars</option>
                <option value="128" <?= ($config['length'] ?? 32) == 128 ? 'selected' : '' ?>>128 chars</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Encoding</label>
            <select name="encoding" class="form-control">
                <option value="hex" <?= ($config['encoding'] ?? 'hex') === 'hex' ? 'selected' : '' ?>>Hexadecimal</option>
                <option value="base64" <?= ($config['encoding'] ?? 'hex') === 'base64' ? 'selected' : '' ?>>Base64</option>
                <option value="alphanumeric" <?= ($config['encoding'] ?? 'hex') === 'alphanumeric' ? 'selected' : '' ?>>Alphanumeric</option>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Auto-rotate interval</label>
        <div class="input-with-suffix">
            <input type="number" name="interval" placeholder="0 = manual only" min="0" value="<?= (int)($config['interval_days'] ?? 30) ?>">
            <span class="suffix">days</span>
        </div>
        <div class="form-hint">Set to 0 to only rotate manually.</div>
    </div>

    <div class="form-group" id="manualValueGroup">
        <label class="form-label">Manual Value (optional)</label>
        <input type="text" name="manual_value" class="form-control" placeholder="Leave blank to auto-generate">
        <div class="form-hint">Provide a specific value, or leave blank for auto-generation.</div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn btn-ghost btn-md js-close-config-modal">
            Cancel
        </button>
        <button type="submit" name="mode" value="rotate_only" class="btn btn-ghost btn-md">
            <i data-lucide="shield-check" style="width:13px;height:13px;"></i>
            Rotate Now
        </button>
        <button type="submit" name="mode" value="save_only" class="btn btn-ghost btn-md">
            Save Config
        </button>
        <button type="submit" name="mode" value="save_and_rotate" class="btn btn-primary btn-md">
            Save + Rotate
        </button>
    </div>
    <div class="form-hint htmx-indicator" id="configSavingIndicator" style="margin-top:8px;">
        Applying changes...
    </div>
</form>

<?php if ($config): ?>
<div class="delete-zone" style="margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border);">
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div style="font-weight:500;font-size:13px;margin-bottom:2px;color:var(--danger);">Stop Managing</div>
            <div style="font-size:11px;color:var(--text-secondary);">Remove auto-rotation config (does not delete the Railway variable)</div>
        </div>
        <button type="button" 
                class="btn btn-danger btn-sm"
                hx-delete="/api/config?name=<?= urlencode($secretName) ?>&serviceId=<?= urlencode($serviceId) ?>&csrf_token=<?= urlencode($csrfToken) ?>"
                hx-target="#secrets-table-body"
                hx-swap="innerHTML"
                hx-confirm="Stop managing '<?= htmlspecialchars($secretName, ENT_QUOTES, 'UTF-8') ?>'? This will not delete the variable itself.">
            <i data-lucide="trash-2" style="width:12px;height:12px;"></i>
            Delete Config
        </button>
    </div>
</div>
<?php endif; ?>
