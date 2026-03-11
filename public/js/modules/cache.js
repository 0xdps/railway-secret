// Railway cache status and sync-cache button

import { showToast, refreshIcons, formatRelativeAge, getCsrfToken } from './utils.js';
import { applyFilters } from './filters.js';
import { updateSidebarActive } from './sidebar.js';
import { applyRelativeTimes } from './utils.js';

export function updateCacheStatus(nowTs) {
    const mainContent = document.getElementById('mainContent');
    const statusEl    = document.getElementById('cacheStatusText');
    if (!mainContent || !statusEl) return;

    const fetchedAt = Number.parseInt(mainContent.dataset.cacheFetchedAt || '0', 10);
    if (!fetchedAt) { statusEl.textContent = 'Cache: not synced'; return; }

    const now = Number.isFinite(nowTs) ? nowTs : Math.floor(Date.now() / 1000);
    const age = Math.max(0, now - fetchedAt);
    statusEl.textContent = `Cache: ${formatRelativeAge(age)}`;
}

export function initCacheHandlers() {
    setInterval(() => updateCacheStatus(), 30_000);

    document.body.addEventListener('click', async (event) => {
        const syncBtn = event.target.closest('#syncCacheBtn');
        if (!syncBtn) return;

        syncBtn.disabled = true;
        const originalText = syncBtn.textContent;
        syncBtn.textContent = 'Syncing...';

        try {
            const serviceId = syncBtn.dataset.serviceId || '';
            const formData  = new FormData();
            formData.append('scope',      'all');
            formData.append('csrf_token', getCsrfToken());
            if (serviceId) formData.append('serviceId', serviceId);

            const refreshResponse = await fetch('/api/cache/refresh', { method: 'POST', body: formData });
            const refreshPayload  = await refreshResponse.json();
            if (!refreshResponse.ok || !refreshPayload.success) {
                showToast(refreshPayload.error || 'Cache refresh failed', 'error');
                return;
            }

            const params = new URLSearchParams();
            if (serviceId) params.set('serviceId', serviceId);
            params.set('refresh', '1');
            const tableResponse = await fetch(`/api/secrets-table?${params.toString()}`);
            if (!tableResponse.ok) { showToast('Cache refreshed but table reload failed', 'error'); return; }

            const tableBody = document.getElementById('secrets-table-body');
            if (tableBody) {
                tableBody.innerHTML = await tableResponse.text();
                refreshIcons();
                applyFilters();
            }

            const mainContent = document.getElementById('mainContent');
            if (mainContent) mainContent.dataset.cacheFetchedAt = String(Math.floor(Date.now() / 1000));
            updateCacheStatus();
            showToast('Cache refreshed from Railway', 'success');

            const currentSection = mainContent ? (mainContent.dataset.section || 'secrets') : 'secrets';
            const sectionParam   = currentSection !== 'secrets' ? `&section=${encodeURIComponent(currentSection)}` : '';
            const nextUrl        = serviceId
                ? `/?serviceId=${encodeURIComponent(serviceId)}&refresh=1${sectionParam}`
                : `/?refresh=1${sectionParam}`;
            window.setTimeout(() => { window.location.href = nextUrl; }, 250);
        } catch {
            showToast('Unable to refresh cache', 'error');
        } finally {
            syncBtn.disabled = false;
            syncBtn.textContent = originalText;
        }
    });
}
