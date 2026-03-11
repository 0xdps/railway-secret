// History modal population, live value fetch, copy, and rollback

import { showToast, refreshIcons, getCsrfToken } from './utils.js';
import { openHistoryModal }                       from './modals.js';

export function initHistoryHandlers() {
    // Open history modal from row button
    document.body.addEventListener('click', async (event) => {
        const valuesBtn = event.target.closest('.js-history-values-btn');
        if (!valuesBtn) return;

        const historyId = valuesBtn.dataset.historyId || '';
        if (!historyId) { showToast('Missing history entry id', 'error'); return; }

        try {
            const response = await fetch(`/api/rotation-history-detail?id=${encodeURIComponent(historyId)}`);
            const payload  = await response.json();
            if (!response.ok || !payload.success || !payload.data) {
                showToast(payload.error || 'Unable to load history values', 'error');
                return;
            }
            const d = payload.data;
            openHistoryModal(
                d.secret_name || '', d.service || '', d.rotated_at || '',
                d.old_value || '', d.new_value || null,
                !!d.new_value_is_live, d.trigger_type || 'manual',
                d.id, d.service_id || ''
            );
        } catch {
            showToast('Unable to load history values', 'error');
        }
    });

    // Fetch live current value on demand
    document.body.addEventListener('click', async (event) => {
        const btn = event.target.closest('#historyShowCurrentBtn');
        if (!btn) return;

        const historyId = btn.dataset.historyId || '';
        if (!historyId) return;

        btn.disabled = true;
        btn.textContent = 'Fetching…';

        try {
            const response = await fetch(`/api/rotation-history-detail?id=${encodeURIComponent(historyId)}&fetch_live=1`);
            const payload  = await response.json();
            if (!response.ok || !payload.success || !payload.data) {
                showToast(payload.error || 'Unable to fetch live value', 'error');
                btn.disabled = false;
                btn.textContent = 'Show current value';
                return;
            }
            const liveValue   = payload.data.new_value;
            const newValueEl  = document.getElementById('historyDetailNewValue');
            const newValueHint = document.getElementById('historyDetailNewValueHint');
            if (newValueEl) {
                if (liveValue) {
                    newValueEl.textContent = liveValue;
                    newValueEl.classList.remove('unavailable');
                    if (newValueHint) {
                        newValueHint.textContent = 'live from Railway';
                        newValueHint.className   = 'history-values-hint live-badge';
                    }
                    btn.style.display = 'none';
                } else {
                    showToast('Value not found in Railway', 'error');
                    btn.disabled = false;
                    btn.textContent = 'Show current value';
                }
            }
        } catch {
            showToast('Unable to fetch live value', 'error');
            btn.disabled = false;
            btn.textContent = 'Show current value';
        }
    });

    // Copy old/new value from history modal
    document.body.addEventListener('click', async (event) => {
        const copyBtn = event.target.closest('.js-copy-history-value');
        if (!copyBtn) return;

        const targetEl = document.getElementById(copyBtn.dataset.target || '');
        if (!targetEl) return;

        const value = targetEl.textContent || '';
        if (targetEl.classList.contains('unavailable') || !value.trim()) {
            showToast('No value to copy', 'error');
            return;
        }

        try {
            await navigator.clipboard.writeText(value);
            copyBtn.classList.add('success');
            const icon = copyBtn.querySelector('[data-lucide]');
            if (icon) { icon.setAttribute('data-lucide', 'check'); refreshIcons(); }
            setTimeout(() => {
                copyBtn.classList.remove('success');
                const resetIcon = copyBtn.querySelector('[data-lucide]');
                if (resetIcon) { resetIcon.setAttribute('data-lucide', 'copy'); refreshIcons(); }
            }, 2000);
        } catch {
            alert('Unable to copy value.');
        }
    });

    // Rollback to previous value from history modal
    document.body.addEventListener('click', async (event) => {
        const btn = event.target.closest('#historyRollbackBtn');
        if (!btn) return;

        const secretName = btn.dataset.secretName || '';
        const serviceId  = btn.dataset.serviceId  || '';
        const historyId  = btn.dataset.historyId  || '';
        if (!secretName) return;

        window.confirmDialog(
            'Rollback secret',
            `Restore <strong>${secretName}</strong> to the value it held <em>before</em> this rotation? This will trigger a Railway redeploy.`,
            async () => {
                btn.disabled = true;
                try {
                    const fd = new FormData();
                    fd.append('key',        secretName);
                    fd.append('serviceId',  serviceId);
                    fd.append('historyId',  historyId);
                    fd.append('csrf_token', getCsrfToken());
                    const res  = await fetch('/api/rollback', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (!res.ok || !json.success) {
                        showToast(json.error || 'Rollback failed', 'error');
                    } else {
                        showToast(`${secretName} rolled back`, 'success');
                        document.getElementById('historyModal')?.classList.remove('open');
                    }
                } catch {
                    showToast('Rollback failed', 'error');
                } finally {
                    btn.disabled = false;
                }
            }
        );
    });
}
