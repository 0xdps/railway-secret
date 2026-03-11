// Config modal: schedule toggle + sync group preset logic

import { getCsrfToken } from './utils.js';

export function initConfigModal() {
    const toggle              = document.getElementById('scheduleToggle');
    const fields              = document.getElementById('scheduleFields');
    const fallback            = document.getElementById('intervalFallback');
    const syncGroupSelect     = document.getElementById('syncGroupSelect');
    const syncGroupHidden     = document.getElementById('syncGroupHidden');
    const syncGroupNewWrap    = document.getElementById('syncGroupNewWrap');
    const syncGroupNewInput   = document.getElementById('syncGroupNewInput');
    const syncGroupConfigsData = document.getElementById('syncGroupConfigsData');
    const lengthSelect        = document.querySelector('select[name="length"]');
    const encodingSelect      = document.querySelector('select[name="encoding"]');
    const intervalInput       = document.querySelector('input[name="interval"]');
    const intervalUnitSelect  = document.querySelector('select[name="interval_unit"]');

    let syncGroupConfigs = {};
    if (syncGroupConfigsData && syncGroupConfigsData.value) {
        try {
            syncGroupConfigs = JSON.parse(syncGroupConfigsData.value) || {};
        } catch {
            syncGroupConfigs = {};
        }
    }

    let applyScheduleToggle = null;

    if (toggle && fields && fallback) {
        applyScheduleToggle = () => {
            const on = toggle.checked;
            fields.style.display = on ? '' : 'none';
            fallback.disabled    = on;
        };
        applyScheduleToggle();
        toggle.addEventListener('change', applyScheduleToggle);
    }

    if (!syncGroupSelect || !syncGroupHidden) return;

    const editGroupPolicyToggleBtn = document.getElementById('editGroupPolicyToggle');
    const editGroupPolicySection   = document.getElementById('editGroupPolicySection');
    const editGroupPolicyClose     = document.getElementById('editGroupPolicyClose');
    const editGroupPolicyForm      = document.getElementById('editGroupPolicyForm');
    const syncGroupLockedNotice    = document.getElementById('syncGroupLockedNotice');
    const editGroupPolicyName      = document.getElementById('editGroupPolicyName');
    const editPolicyLength         = document.getElementById('editPolicyLength');
    const editPolicyEncoding       = document.getElementById('editPolicyEncoding');
    const editPolicyInterval       = document.getElementById('editPolicyInterval');
    const editPolicyUnit           = document.getElementById('editPolicyUnit');

    const setGroupPolicyLock = (lock) => {
        document.querySelectorAll('[data-policy-field]').forEach(el => { el.disabled = lock; });
        if (syncGroupLockedNotice) syncGroupLockedNotice.style.display = lock ? '' : 'none';
        if (!lock && editGroupPolicySection) editGroupPolicySection.style.display = 'none';
    };

    const applyGroupPreset = (groupName) => {
        const preset = syncGroupConfigs[groupName];
        if (!preset) return;

        if (lengthSelect && preset.length) {
            lengthSelect.value = String(preset.length);
            lengthSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (encodingSelect && preset.encoding) {
            encodingSelect.value = String(preset.encoding);
            encodingSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }

        const intervalDays = Number.parseInt(String(preset.interval_days ?? 0), 10) || 0;
        if (intervalInput) intervalInput.value = intervalDays > 0 ? String(intervalDays) : '1';

        if (intervalUnitSelect && preset.interval_unit) {
            intervalUnitSelect.value = String(preset.interval_unit);
            intervalUnitSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (toggle && typeof applyScheduleToggle === 'function') {
            toggle.checked = intervalDays > 0;
            applyScheduleToggle();
        }

        setGroupPolicyLock(true);
        if (editGroupPolicyName)                        editGroupPolicyName.value  = groupName;
        if (editPolicyLength  && preset.length)         editPolicyLength.value     = String(preset.length);
        if (editPolicyEncoding && preset.encoding)      editPolicyEncoding.value   = String(preset.encoding);
        if (editPolicyInterval)                         editPolicyInterval.value   = String(intervalDays);
        if (editPolicyUnit && preset.interval_unit)     editPolicyUnit.value       = String(preset.interval_unit);
    };

    const applySyncGroupSelect = () => {
        const selected = syncGroupSelect.value || '';
        if (selected === '__new__') {
            if (syncGroupNewWrap) syncGroupNewWrap.style.display = '';
            syncGroupHidden.value = syncGroupNewInput ? syncGroupNewInput.value.trim() : '';
            setGroupPolicyLock(false);
        } else {
            if (syncGroupNewWrap) syncGroupNewWrap.style.display = 'none';
            syncGroupHidden.value = selected;
            if (selected && syncGroupConfigs[selected]) {
                applyGroupPreset(selected);
            } else {
                setGroupPolicyLock(false);
            }
        }
    };

    syncGroupSelect.addEventListener('change', applySyncGroupSelect);
    if (syncGroupNewInput) syncGroupNewInput.addEventListener('input', applySyncGroupSelect);
    applySyncGroupSelect();

    if (editGroupPolicyToggleBtn && editGroupPolicySection) {
        editGroupPolicyToggleBtn.addEventListener('click', () => {
            const isOpen = editGroupPolicySection.style.display !== 'none';
            editGroupPolicySection.style.display = isOpen ? 'none' : '';
        });
    }
    if (editGroupPolicyClose && editGroupPolicySection) {
        editGroupPolicyClose.addEventListener('click', () => {
            editGroupPolicySection.style.display = 'none';
        });
    }

    if (editGroupPolicyForm) {
        editGroupPolicyForm.addEventListener('htmx:afterRequest', (ev) => {
            if (ev.detail.successful && editGroupPolicyName) {
                const gName = editGroupPolicyName.value;
                if (gName) {
                    syncGroupConfigs[gName] = {
                        length:        parseInt(editPolicyLength?.value  || '32', 10),
                        encoding:      editPolicyEncoding?.value || 'hex',
                        interval_days: parseInt(editPolicyInterval?.value || '0', 10),
                        interval_unit: editPolicyUnit?.value || 'day',
                    };
                    applyGroupPreset(gName);
                }
                if (editGroupPolicySection) editGroupPolicySection.style.display = 'none';
            }
        });
    }

    const deleteGroupBtn = document.getElementById('deleteGroupPolicyBtn');
    if (deleteGroupBtn) {
        deleteGroupBtn.addEventListener('click', async () => {
            const gName = deleteGroupBtn.dataset.groupName || '';
            const csrf  = deleteGroupBtn.dataset.csrfToken || getCsrfToken();
            if (!gName) return;
            if (!confirm(`Delete sync group "${gName}"?\n\nAll members become independent secrets. No secrets are deleted from Railway.`)) return;

            deleteGroupBtn.disabled = true;
            try {
                const res = await fetch('/api/sync-group-config', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ group_name: gName, csrf_token: csrf }),
                });
                if (res.ok) {
                    const triggerHeader = res.headers.get('HX-Trigger');
                    if (triggerHeader) {
                        try {
                            const ev = JSON.parse(triggerHeader);
                            if (ev.rotatorToast) {
                                document.dispatchEvent(new CustomEvent('rotatorToast', { detail: ev.rotatorToast }));
                            }
                        } catch {}
                    }
                    document.querySelector('.js-close-config-modal')?.click();
                } else {
                    const data = await res.json().catch(() => ({}));
                    alert('Error: ' + (data.error || res.status));
                }
            } catch (err) {
                alert('Network error: ' + err.message);
            } finally {
                deleteGroupBtn.disabled = false;
            }
        });
    }
}
