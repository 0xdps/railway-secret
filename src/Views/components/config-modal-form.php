<?php
/**
 * Configuration modal form content
 *
 * Required variables:
 * @var string     $secretName
 * @var array|null $config
 * @var string     $serviceId
 * @var string     $csrfToken
 */

$hasConfig      = !empty($config);
$scheduleOn     = $hasConfig && (int)($config['interval_days'] ?? 0) > 0;
$savedInterval  = $hasConfig ? (int)($config['interval_days'] ?? 1) : 1;
$savedUnit      = $config['interval_unit'] ?? 'day';
$savedLength    = (int)($config['length'] ?? 32);
$savedEncoding  = $config['encoding'] ?? 'hex';
?>
<div class="modal-header">
    <span class="modal-title">
        <i data-lucide="settings-2" style="width:13px;height:13px;margin-right:5px;vertical-align:-1px;"></i>
        Configure: <?= htmlspecialchars($secretName) ?>
    </span>
    <button class="modal-close js-close-config-modal" type="button">
        <i data-lucide="x" style="width:14px;height:14px;"></i>
    </button>
</div>

<form hx-post="/api/config"
      hx-target="#secrets-table-body"
      hx-swap="innerHTML"
      hx-indicator="#configSavingIndicator">

    <input type="hidden" name="name"       value="<?= htmlspecialchars($secretName) ?>">
    <input type="hidden" name="serviceId"  value="<?= htmlspecialchars((string)$serviceId) ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

    <!-- ── Section 1: Generation ──────────────────────────────── -->
    <div class="config-section-label">Generation</div>
    <div class="config-section-hint">How new values are generated when this secret rotates.</div>

    <div class="form-row" style="margin-top:12px;">
        <div class="form-group">
            <label class="form-label">Length</label>
            <select name="length" class="form-control">
                <option value="16"  <?= $savedLength === 16  ? 'selected' : '' ?>>16 chars</option>
                <option value="32"  <?= $savedLength === 32  ? 'selected' : '' ?>>32 chars</option>
                <option value="64"  <?= $savedLength === 64  ? 'selected' : '' ?>>64 chars</option>
                <option value="128" <?= $savedLength === 128 ? 'selected' : '' ?>>128 chars</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Format</label>
            <select name="encoding" class="form-control">
                <option value="hex"          <?= $savedEncoding === 'hex'          ? 'selected' : '' ?>>Hex</option>
                <option value="base64"       <?= $savedEncoding === 'base64'       ? 'selected' : '' ?>>Base64</option>
                <option value="alphanumeric" <?= $savedEncoding === 'alphanumeric' ? 'selected' : '' ?>>Alphanumeric</option>
            </select>
        </div>
    </div>

    <!-- ── Section 2: Schedule ────────────────────────────────── -->
    <div style="border-top:1px solid var(--border);padding-top:16px;margin-bottom:16px;">
        <label class="config-toggle-row">
            <span class="config-section-label" style="margin:0;">Auto-rotation</span>
            <span class="config-toggle-wrap">
                <input type="checkbox" id="scheduleToggle" class="config-toggle-input"
                       <?= $scheduleOn ? 'checked' : '' ?>>
                <span class="config-toggle-track"></span>
            </span>
        </label>
        <div class="config-section-hint" style="margin-top:2px;">
            When enabled, cron rotates this secret automatically on the schedule below.
        </div>

        <div id="scheduleFields" style="margin-top:12px;<?= $scheduleOn ? '' : 'display:none;' ?>">
            <label class="form-label">Rotate every</label>
            <div class="input-with-suffix">
                <input type="number" name="interval" min="1" value="<?= $savedInterval ?>"
                       placeholder="e.g. 7">
                <select name="interval_unit" class="form-control" style="width:auto;min-width:95px;border:none;border-left:1px solid var(--border);border-radius:0;background:var(--bg-base);">
                    <?php foreach (['minute' => 'minutes', 'hour' => 'hours', 'day' => 'days'] as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $savedUnit === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <!-- When schedule is OFF send interval=0 so backend treats it as on-demand -->
        <input type="hidden" name="interval" id="intervalFallback" value="0"
               <?= $scheduleOn ? 'disabled' : '' ?>>
    </div>

    <!-- ── Actions ───────────────────────────────────────────── -->
    <div class="modal-actions">
        <button type="submit" name="mode" value="save_only" class="btn btn-primary btn-md" style="width:100%;justify-content:center;">
            <i data-lucide="save" style="width:13px;height:13px;"></i>
            Save Config
        </button>
    </div>

    <div class="form-hint htmx-indicator" id="configSavingIndicator" style="margin-top:8px;">
        Applying changes...
    </div>
</form>

<?php if ($hasConfig): ?>
<div class="delete-zone" style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);">
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div style="font-weight:500;font-size:13px;margin-bottom:2px;color:var(--danger);">Stop Managing</div>
            <div style="font-size:11px;color:var(--text-secondary);">Removes rotation config — does not delete the Railway variable.</div>
        </div>
        <button type="button"
                class="btn btn-danger btn-sm js-delete-config"
                data-secret-name="<?= htmlspecialchars($secretName, ENT_QUOTES, 'UTF-8') ?>"
                data-delete-url="/api/config?name=<?= urlencode($secretName) ?>&serviceId=<?= urlencode((string)($serviceId ?? '')) ?>&csrf_token=<?= urlencode($csrfToken) ?>">
            <i data-lucide="trash-2" style="width:12px;height:12px;"></i>
            Remove
        </button>
    </div>
</div>
<?php endif; ?>
