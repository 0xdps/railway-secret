<?php
/**
 * Rotate modal form — quick rotation popup
 *
 * Required variables:
 * @var string     $secretName
 * @var array|null $config      Saved config (for pre-filling defaults)
 * @var string     $serviceId
 * @var string     $csrfToken
 */

$savedLength   = (int)($config['length']   ?? 32);
$savedEncoding = $config['encoding']       ?? 'hex';
$syncGroupName = trim((string)($config['sync_group'] ?? ''));
$syncGroupMembers = isset($syncGroupMembers) && is_array($syncGroupMembers) ? $syncGroupMembers : [];
?>
<div class="modal-header">
    <span class="modal-title">
        <i data-lucide="rotate-cw" style="width:13px;height:13px;margin-right:5px;vertical-align:-1px;"></i>
        Rotate: <?= htmlspecialchars($secretName) ?>
    </span>
    <button class="modal-close js-close-config-modal" type="button">
        <i data-lucide="x" style="width:14px;height:14px;"></i>
    </button>
</div>

<form hx-post="/api/config"
      hx-target="#secrets-table-body"
      hx-swap="innerHTML"
      hx-indicator="#rotateSavingIndicator">

    <input type="hidden" name="name"       value="<?= htmlspecialchars($secretName) ?>">
    <input type="hidden" name="serviceId"  value="<?= htmlspecialchars((string)$serviceId) ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="mode"       value="rotate_only">

    <p class="config-section-hint" style="margin-bottom:16px;">
        A new value will be generated and applied to Railway immediately.
        Adjust the settings below only if you want different values for this rotation.
    </p>

    <?php if ($syncGroupName !== '' && !empty($syncGroupMembers)): ?>
    <div style="margin-bottom:14px;border:1px solid rgba(59,130,246,0.25);background:rgba(59,130,246,0.08);border-radius:var(--radius-sm);padding:10px 12px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--accent);margin-bottom:6px;">Sync Group Impact</div>
        <div class="form-hint" style="margin:0 0 6px 0;color:var(--text-secondary);">
            This key belongs to <strong><?= htmlspecialchars($syncGroupName) ?></strong>. Rotating now will also rotate:
        </div>
        <ul style="margin:0;padding-left:16px;color:var(--text-secondary);font-size:12px;line-height:1.5;">
            <?php foreach ($syncGroupMembers as $member): ?>
                <li><strong><?= htmlspecialchars((string)($member['secret_name'] ?? '')) ?></strong> in <?= htmlspecialchars((string)($member['service'] ?? '')) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="form-row">
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

    <div class="form-group" style="border-top:1px solid var(--border);padding-top:14px;">
        <label class="form-label">Override value <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-muted);font-size:10px;">(optional)</span></label>
        <input type="text" name="manual_value" class="form-control"
               placeholder="Leave blank to auto-generate">
        <div class="form-hint">Only needed when setting an externally-issued credential (e.g. a Stripe key or OAuth secret).</div>
    </div>

    <div class="modal-actions">
        <button type="submit" class="btn btn-primary btn-md">
            <i data-lucide="rotate-cw" style="width:13px;height:13px;"></i>
            Rotate Now
        </button>
    </div>

    <div class="form-hint htmx-indicator" id="rotateSavingIndicator" style="margin-top:8px;">
        Rotating...
    </div>
</form>
