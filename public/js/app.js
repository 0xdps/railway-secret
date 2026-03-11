// Main entry point – loaded as type="module" (deferred, strict mode)

import { refreshIcons, getCsrfToken, showToast, applyRelativeTimes } from './modules/utils.js';
import { initCustomSelects, closeAllCustomSelects }                   from './modules/custom-select.js';
import { openConfigModal, closeConfigModal, initModalHandlers }       from './modules/modals.js';
import {
    applyFilters, initRailwayToggleState, applyManagedGroupFilter,
    sortTable, initFilterHandlers,
} from './modules/filters.js';
import { updateSidebarActive, initSidebarHandlers } from './modules/sidebar.js';
import { initSecretHandlers }                        from './modules/secrets.js';
import { initHistoryHandlers }                       from './modules/history.js';
import { updateCacheStatus, initCacheHandlers }      from './modules/cache.js';
import { initConfigModal }                           from './modules/config-modal.js';
import { initGroupHandlers }                         from './modules/groups.js';

function updateDocumentTitle() {
    const mainContent = document.getElementById('mainContent');
    if (!mainContent) return;
    const viewTitle = (mainContent.dataset.viewTitle || '').trim();
    if (viewTitle) { document.title = `${viewTitle} — Railway Secrets`; return; }
    const heading = mainContent.querySelector('.page-header-left h1');
    if (heading && heading.textContent.trim()) {
        document.title = `${heading.textContent.trim()} — Railway Secrets`;
    }
}

// ── Initialise all modules ─────────────────────────────────────────────────────
initModalHandlers();   // sets window.confirmDialog, wires backdrops + escape key
initFilterHandlers();
initSidebarHandlers();
initSecretHandlers();
initHistoryHandlers();
initCacheHandlers();
initGroupHandlers();

// ── One-time page setup ────────────────────────────────────────────────────────
refreshIcons();
initCustomSelects();
initRailwayToggleState();
applyFilters();
applyManagedGroupFilter();
applyRelativeTimes();
updateSidebarActive();
updateDocumentTitle();
updateCacheStatus();
initConfigModal();

// ── Outside-click: close open custom selects ───────────────────────────────────
document.addEventListener('click', (event) => {
    if (!event.target.closest('.custom-select')) closeAllCustomSelects();
});

// ── Main delegated click handler ───────────────────────────────────────────────
document.body.addEventListener('click', async (event) => {
    // Sort table columns
    const sortBtn = event.target.closest('.sort-btn');
    if (sortBtn) {
        document.querySelectorAll('.sort-btn').forEach(b => b.classList.remove('active'));
        sortBtn.classList.add('active');
        sortTable(sortBtn.dataset.sort || 'name-asc');
        return;
    }

    // Config modal open/close
    const openConfigBtn = event.target.closest('.js-open-config-modal');
    if (openConfigBtn) { openConfigModal(); return; }

    const closeConfigBtn = event.target.closest('.js-close-config-modal');
    if (closeConfigBtn) { closeConfigModal(); return; }

    // Rotate all due secrets
    const rotateDueBtn = event.target.closest('#rotateDueBtn');
    if (rotateDueBtn) {
        rotateDueBtn.disabled = true;
        const originalHtml = rotateDueBtn.innerHTML;
        rotateDueBtn.textContent = 'Rotating…';
        const fd = new FormData();
        fd.append('csrf_token', getCsrfToken());
        fetch('/api/rotate-all-due', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(payload => {
                if (!payload.success) { showToast(payload.error || 'Rotation failed', 'error'); return; }
                showToast(payload.message || 'Done', 'success');
                const mc = document.getElementById('mainContent');
                if (!mc) return;
                fetch('/managed', { headers: { 'HX-Request': 'true' } })
                    .then(r => r.text())
                    .then(html => {
                        mc.outerHTML = html;
                        refreshIcons();
                        applyRelativeTimes();
                        updateSidebarActive();
                    })
                    .catch(() => {});
            })
            .catch(() => showToast('Unable to rotate', 'error'))
            .finally(() => {
                rotateDueBtn.disabled  = false;
                rotateDueBtn.innerHTML = originalHtml;
                refreshIcons();
            });
        return;
    }

    // Rotate sync group
    const rotateSyncGroupBtn = event.target.closest('.js-rotate-sync-group');
    if (rotateSyncGroupBtn) {
        const groupName = rotateSyncGroupBtn.dataset.syncGroup || '';
        if (!groupName) { showToast('Missing sync group', 'error'); return; }
        window.confirmDialog(
            'Rotate sync group',
            `Rotate every secret linked to <strong>${groupName}</strong> to one shared value? This triggers redeploys for affected services.`,
            async () => {
                rotateSyncGroupBtn.disabled = true;
                try {
                    const fd = new FormData();
                    fd.append('groupName', groupName);
                    fd.append('csrf_token', getCsrfToken());
                    const response = await fetch('/api/rotate-sync-group', { method: 'POST', body: fd });
                    const payload  = await response.json();
                    if (!response.ok || !payload.success) {
                        showToast(payload.error || 'Sync group rotation failed', 'error');
                        return;
                    }
                    showToast(`${groupName} rotated (${payload.rotated || 0} secrets)`, 'success');
                    const mc = document.getElementById('mainContent');
                    if (!mc) return;
                    fetch('/managed', { headers: { 'HX-Request': 'true' } })
                        .then(r => r.text())
                        .then(html => {
                            mc.outerHTML = html;
                            refreshIcons();
                            applyRelativeTimes();
                            updateSidebarActive();
                        })
                        .catch(() => {});
                } catch {
                    showToast('Sync group rotation failed', 'error');
                } finally {
                    rotateSyncGroupBtn.disabled = false;
                }
            }
        );
        return;
    }

    // Row-level rollback
    const rollbackRowBtn = event.target.closest('.js-rollback-btn');
    if (!rollbackRowBtn) return;

    const secretName = rollbackRowBtn.dataset.secretName || '';
    const serviceId  = rollbackRowBtn.dataset.serviceId  || '';
    if (!secretName) return;

    window.confirmDialog(
        'Rollback last rotation',
        `Restore <strong>${secretName}</strong> to the value it held before its last rotation? This will trigger a Railway redeploy.`,
        async () => {
            rollbackRowBtn.disabled = true;
            try {
                const fd = new FormData();
                fd.append('key', secretName);
                fd.append('serviceId', serviceId);
                fd.append('csrf_token', getCsrfToken());
                const res  = await fetch('/api/rollback', { method: 'POST', body: fd });
                const json = await res.json();
                if (!res.ok || !json.success) {
                    showToast(json.error || 'Rollback failed', 'error');
                } else {
                    showToast(`${secretName} rolled back`, 'success');
                }
            } catch {
                showToast('Rollback failed', 'error');
            } finally {
                rollbackRowBtn.disabled = false;
            }
        }
    );
});

// ── HTMX lifecycle hooks ───────────────────────────────────────────────────────
document.body.addEventListener('htmx:afterSwap', () => {
    refreshIcons();
    initCustomSelects();
    initRailwayToggleState();
    applyFilters();
    applyManagedGroupFilter();
    applyRelativeTimes();
    updateSidebarActive();
    updateDocumentTitle();
    updateCacheStatus();
    initConfigModal();  // re-wire modal controls after HTMX swap
    if (typeof window.initTocScrollSpy === 'function') window.initTocScrollSpy();
});

document.body.addEventListener('htmx:afterSettle', () => {
    refreshIcons();
    initCustomSelects();
    applyRelativeTimes();
});

// Close config modal + refresh managed page after successful form submit inside modal
document.body.addEventListener('htmx:afterRequest', (event) => {
    const detail     = event.detail || {};
    const source     = detail.elt;
    const successful = Boolean(detail.successful);
    if (!successful || !source) return;

    if (source.closest && source.closest('#configModal')) {
        closeConfigModal();
        const mainContent = document.getElementById('mainContent');
        if (mainContent && mainContent.dataset.section === 'managed') {
            fetch('/managed', { headers: { 'HX-Request': 'true' } })
                .then(r => r.text())
                .then(html => {
                    mainContent.outerHTML = html;
                    refreshIcons();
                    applyRelativeTimes();
                    updateSidebarActive();
                })
                .catch(() => {});
        }
    }
});

document.body.addEventListener('htmx:responseError', () => {
    showToast('Request failed. Please retry.', 'error');
});

document.body.addEventListener('rotatorToast', (event) => {
    const detail  = event.detail || {};
    const message = detail.message || detail.value || 'Done';
    showToast(message, detail.type || 'success');
});
