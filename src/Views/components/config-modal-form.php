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
$savedSyncGroup = trim((string)($config['sync_group'] ?? ''));
$syncGroups = isset($syncGroups) && is_array($syncGroups) ? array_values(array_unique(array_filter($syncGroups, static fn($g) => trim((string)$g) !== ''))) : [];
$syncGroupConfigs = isset($syncGroupConfigs) && is_array($syncGroupConfigs) ? $syncGroupConfigs : [];
$isExistingSyncGroup = $savedSyncGroup !== '' && in_array($savedSyncGroup, $syncGroups, true);
$syncGroupConfigsJson = htmlspecialchars((string)json_encode($syncGroupConfigs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
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
    <input type="hidden" id="syncGroupConfigsData" value="<?= $syncGroupConfigsJson ?>">

    <!-- ── Section 1: Sync Group ──────────────────────────────── -->
    <div class="config-section-label">Sync Group</div>
    <div class="config-section-hint">Choose the shared rotation group first. Existing groups auto-fill the config below.</div>

    <div class="form-group" style="margin-top:12px;">
        <label class="form-label">Group</label>
        <select id="syncGroupSelect" class="form-control">
            <option value="">No sync group</option>
            <?php foreach ($syncGroups as $group): ?>
                <option value="<?= htmlspecialchars((string)$group, ENT_QUOTES, 'UTF-8') ?>" <?= $isExistingSyncGroup && $savedSyncGroup === $group ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$group) ?>
                </option>
            <?php endforeach; ?>
            <option value="__new__" <?= (!$isExistingSyncGroup && $savedSyncGroup !== '') ? 'selected' : '' ?>>+ Create new group</option>
        </select>
        <div id="syncGroupNewWrap" style="margin-top:8px;<?= (!$isExistingSyncGroup && $savedSyncGroup !== '') ? '' : 'display:none;' ?>">
            <input type="text"
                   id="syncGroupNewInput"
                   class="form-control"
                   maxlength="64"
                   value="<?= htmlspecialchars(!$isExistingSyncGroup ? $savedSyncGroup : '', ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="e.g. primary-db-url">
        </div>
        <input type="hidden" name="sync_group" id="syncGroupHidden" value="<?= htmlspecialchars($savedSyncGroup, ENT_QUOTES, 'UTF-8') ?>">
        <div class="config-section-hint" style="margin-top:6px;">
            All group members share the same generated value, format, and schedule.
        </div>
    </div>

    <!-- Locked notice + Edit Group Policy (visible only for existing groups) -->
    <div id="syncGroupLockedNotice" style="<?= $isExistingSyncGroup ? '' : 'display:none;' ?>margin-bottom:4px;">
        <div style="display:flex;align-items:center;justify-content:space-between;background:var(--surface-raised);border:1px solid var(--border);border-radius:6px;padding:7px 12px;font-size:11px;color:var(--text-secondary);">
            <span>
                <i data-lucide="lock" style="width:11px;height:11px;margin-right:4px;vertical-align:-1px;"></i>
                Generation and schedule are controlled by group policy
            </span>
            <button type="button" id="editGroupPolicyToggle"
                    style="background:none;border:none;cursor:pointer;color:var(--accent);font-size:11px;font-weight:500;padding:0;">
                <i data-lucide="pencil" style="width:10px;height:10px;vertical-align:-1px;"></i>
                Edit policy
            </button>
        </div>
    </div>
    <div id="editGroupPolicySection" style="display:none;background:var(--surface-raised);border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:12px;">
        <div style="font-size:12px;font-weight:600;color:var(--text);margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;">
            <span>
                <i data-lucide="pencil" style="width:11px;height:11px;margin-right:4px;vertical-align:-1px;"></i>
                Edit Group Policy
            </span>
            <button type="button" id="editGroupPolicyClose"
                    style="background:none;border:none;cursor:pointer;color:var(--text-secondary);padding:0;line-height:1;">
                <i data-lucide="x" style="width:12px;height:12px;"></i>
            </button>
        </div>
        <div class="config-section-hint" style="margin-bottom:10px;">Updating policy propagates to all group members instantly.</div>
        <form hx-post="/api/sync-group-config" hx-swap="none" id="editGroupPolicyForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="group_name" id="editGroupPolicyName" value="<?= htmlspecialchars($savedSyncGroup, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-row" style="margin-bottom:10px;">
                <div class="form-group">
                    <label class="form-label">Length</label>
                    <select name="length" id="editPolicyLength" class="form-control">
                        <?php foreach ([16, 32, 64, 128] as $l): ?>
                            <option value="<?= $l ?>" <?= $savedLength === $l ? 'selected' : '' ?>><?= $l ?> chars</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Format</label>
                    <select name="encoding" id="editPolicyEncoding" class="form-control">
                        <option value="hex"          <?= $savedEncoding === 'hex'          ? 'selected' : '' ?>>Hex</option>
                        <option value="base64"       <?= $savedEncoding === 'base64'       ? 'selected' : '' ?>>Base64</option>
                        <option value="alphanumeric" <?= $savedEncoding === 'alphanumeric' ? 'selected' : '' ?>>Alphanumeric</option>
                    </select>
                </div>
            </div>
            <div class="form-group" style="margin-bottom:10px;">
                <label class="form-label">Rotate every <small style="font-weight:400;color:var(--text-secondary);">(0 = manual only)</small></label>
                <div class="input-with-suffix">
                    <input type="number" name="interval" id="editPolicyInterval" min="0" value="<?= $savedInterval ?>" placeholder="0">
                    <select name="interval_unit" id="editPolicyUnit" class="form-control suffix-select">
                        <?php foreach (['minute' => 'minutes', 'hour' => 'hours', 'day' => 'days'] as $val => $lbl): ?>
                            <option value="<?= $val ?>" <?= $savedUnit === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;">
                <i data-lucide="save" style="width:12px;height:12px;"></i>
                Save Group Policy
            </button>
        </form>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border);">
            <button type="button"
                    id="deleteGroupPolicyBtn"
                    class="btn btn-danger btn-sm"
                    style="width:100%;justify-content:center;"
                    data-group-name="<?= htmlspecialchars($savedSyncGroup, ENT_QUOTES, 'UTF-8') ?>"
                    data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <i data-lucide="trash-2" style="width:12px;height:12px;"></i>
                Delete Group
            </button>
            <div style="font-size:10px;color:var(--text-secondary);margin-top:4px;text-align:center;">Members become independent secrets — no data is deleted.</div>
        </div>
    </div>

    <!-- ── Section 2: Generation ──────────────────────────────── -->
    <div class="config-section-label">Generation</div>
    <div class="config-section-hint">How new values are generated when this secret rotates.</div>

    <div class="form-row" style="margin-top:12px;">
        <div class="form-group">
            <label class="form-label">Length</label>
            <select name="length" class="form-control" data-policy-field>
                <option value="16"  <?= $savedLength === 16  ? 'selected' : '' ?>>16 chars</option>
                <option value="32"  <?= $savedLength === 32  ? 'selected' : '' ?>>32 chars</option>
                <option value="64"  <?= $savedLength === 64  ? 'selected' : '' ?>>64 chars</option>
                <option value="128" <?= $savedLength === 128 ? 'selected' : '' ?>>128 chars</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Format</label>
            <select name="encoding" class="form-control" data-policy-field>
                <option value="hex"          <?= $savedEncoding === 'hex'          ? 'selected' : '' ?>>Hex</option>
                <option value="base64"       <?= $savedEncoding === 'base64'       ? 'selected' : '' ?>>Base64</option>
                <option value="alphanumeric" <?= $savedEncoding === 'alphanumeric' ? 'selected' : '' ?>>Alphanumeric</option>
            </select>
        </div>
    </div>

    <!-- ── Section 3: Schedule ────────────────────────────────── -->
    <div style="border-top:1px solid var(--border);padding-top:16px;margin-bottom:16px;">
        <label class="config-toggle-row">
            <span class="config-section-label" style="margin:0;">Auto-rotation</span>
            <span class="config-toggle-wrap">
                <input type="checkbox" id="scheduleToggle" class="config-toggle-input"
                       data-policy-field <?= $scheduleOn ? 'checked' : '' ?>>
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
                       placeholder="e.g. 7" data-policy-field>
                <select name="interval_unit" class="form-control suffix-select" data-policy-field>
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
