// Dashboard interactions - CSP-safe HTMX helpers
document.addEventListener('DOMContentLoaded', () => {
    const configModal = document.getElementById('configModal');
    const historyModal = document.getElementById('historyModal');
    const csrfToken = document.body.dataset.csrfToken || '';

    function refreshIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    function applyFilters() {
        const toggleRailway = document.getElementById('toggleRailway');
        const secretSearch = document.getElementById('secretSearch');
        const showRailway = toggleRailway ? toggleRailway.checked : true;
        const query = secretSearch ? secretSearch.value.trim().toLowerCase() : '';
        const rows = document.querySelectorAll('.secret-tr[data-is-railway="1"]');
        rows.forEach((row) => {
            const name = (row.dataset.keyName || '').toLowerCase();
            const matchesRailway = showRailway || row.dataset.isRailway !== '1';
            const matchesSearch = query === '' || name.includes(query);
            row.style.display = matchesRailway && matchesSearch ? '' : 'none';
        });

        const nonRailwayRows = document.querySelectorAll('.secret-tr[data-is-railway="0"]');
        nonRailwayRows.forEach((row) => {
            const name = (row.dataset.keyName || '').toLowerCase();
            const matchesSearch = query === '' || name.includes(query);
            row.style.display = matchesSearch ? '' : 'none';
        });
    }

    function updateSidebarActive() {
        const mainContent = document.getElementById('mainContent');
        const currentServiceId = mainContent ? (mainContent.dataset.serviceId || '') : '';
        const currentSection = mainContent ? (mainContent.dataset.section || 'secrets') : 'secrets';
        document.querySelectorAll('.js-scope-nav').forEach((link) => {
            const linkServiceId = link.dataset.serviceId || '';
            link.classList.toggle('active', currentSection !== 'history' && linkServiceId === currentServiceId);
        });

        document.querySelectorAll('.js-history-nav').forEach((link) => {
            link.classList.toggle('active', currentSection === 'history');
        });
    }

    function formatRelativeAge(secondsAgo) {
        if (!Number.isFinite(secondsAgo) || secondsAgo < 0) {
            return '--';
        }
        if (secondsAgo < 5) {
            return 'just now';
        }
        if (secondsAgo < 60) {
            return `${secondsAgo}s ago`;
        }
        if (secondsAgo < 3600) {
            return `${Math.floor(secondsAgo / 60)}m ago`;
        }
        if (secondsAgo < 86400) {
            return `${Math.floor(secondsAgo / 3600)}h ago`;
        }
        return `${Math.floor(secondsAgo / 86400)}d ago`;
    }

    function updateCacheStatus(nowTs) {
        const mainContent = document.getElementById('mainContent');
        const statusEl = document.getElementById('cacheStatusText');
        if (!mainContent || !statusEl) {
            return;
        }

        const fetchedAt = Number.parseInt(mainContent.dataset.cacheFetchedAt || '0', 10);
        if (!fetchedAt) {
            statusEl.textContent = 'Cache: not synced';
            return;
        }

        const now = Number.isFinite(nowTs) ? nowTs : Math.floor(Date.now() / 1000);
        const age = Math.max(0, now - fetchedAt);
        statusEl.textContent = `Cache: ${formatRelativeAge(age)}`;
    }

    function updateDocumentTitle() {
        const mainContent = document.getElementById('mainContent');
        if (!mainContent) {
            return;
        }

        const viewTitle = (mainContent.dataset.viewTitle || '').trim();
        if (viewTitle) {
            document.title = `${viewTitle} — Railway Secrets`;
            return;
        }

        const heading = mainContent.querySelector('.page-header-left h1');
        if (heading && heading.textContent.trim()) {
            document.title = `${heading.textContent.trim()} — Railway Secrets`;
        }
    }

    function showToast(message, type = 'success') {
        if (!message) {
            return;
        }

        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `toast toast-${type === 'error' ? 'error' : 'success'}`;
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('toast-hide');
            setTimeout(() => toast.remove(), 220);
        }, 2200);
    }

    function openConfigModal() {
        if (configModal) {
            configModal.classList.add('open');
        }
    }

    function closeConfigModal() {
        if (configModal) {
            configModal.classList.remove('open');
        }
    }

    function openHistoryModal(secret, service, rotatedAt) {
        if (!historyModal) {
            return;
        }

        const secretEl = document.getElementById('historyDetailSecret');
        const serviceEl = document.getElementById('historyDetailService');
        const timeEl = document.getElementById('historyDetailTime');
        if (secretEl) {
            secretEl.textContent = secret || '--';
        }
        if (serviceEl) {
            serviceEl.textContent = service || '--';
        }
        if (timeEl) {
            timeEl.textContent = rotatedAt || '--';
        }

        historyModal.classList.add('open');
    }

    function closeHistoryModal() {
        if (historyModal) {
            historyModal.classList.remove('open');
        }
    }

    // Make available for any future non-inline integrations.
    window.filterRailwayVars = applyFilters;

    refreshIcons();
    applyFilters();
    updateSidebarActive();
    updateDocumentTitle();
    updateCacheStatus();
    setInterval(() => updateCacheStatus(), 30000);

    document.body.addEventListener('change', (event) => {
        if (event.target && event.target.id === 'toggleRailway') {
            applyFilters();
        }
    });

    document.body.addEventListener('input', (event) => {
        if (event.target && event.target.id === 'secretSearch') {
            applyFilters();
        }
    });

    if (configModal) {
        configModal.addEventListener('click', (event) => {
            if (event.target === configModal) {
                closeConfigModal();
            }
        });
    }

    if (historyModal) {
        historyModal.addEventListener('click', (event) => {
            if (event.target === historyModal) {
                closeHistoryModal();
            }
        });
    }

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeConfigModal();
            closeHistoryModal();
        }
    });

    // HTMX lifecycle hooks for dynamic fragments.
    document.body.addEventListener('htmx:afterSwap', () => {
        refreshIcons();
        applyFilters();
        updateSidebarActive();
        updateDocumentTitle();
        updateCacheStatus();
    });
    document.body.addEventListener('htmx:afterSettle', refreshIcons);

    // Open/close modal controls without inline handlers.
    document.body.addEventListener('click', (event) => {
        const openBtn = event.target.closest('.js-open-config-modal');
        if (openBtn) {
            openConfigModal();
            return;
        }

        const closeBtn = event.target.closest('.js-close-config-modal');
        if (closeBtn) {
            closeConfigModal();
            return;
        }

        const closeHistoryBtn = event.target.closest('.js-close-history-modal');
        if (closeHistoryBtn) {
            closeHistoryModal();
            return;
        }

        const toggleBtn = event.target.closest('.js-toggle-secret');
        if (toggleBtn) {
            const row = toggleBtn.closest('.secret-row');
            if (!row) {
                return;
            }

            const span = row.querySelector('.secret-value');
            if (!span) {
                return;
            }

            const isHidden = span.textContent.trim() === '••••••••••••';
            span.textContent = isHidden ? span.dataset.value : '••••••••••••';
            span.classList.toggle('revealed', isHidden);

            const icon = toggleBtn.querySelector('[data-lucide]');
            if (icon) {
                icon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
                refreshIcons();
            }
            return;
        }
    });

    document.body.addEventListener('click', async (event) => {
        const copyBtn = event.target.closest('.js-copy-secret');
        if (!copyBtn) {
            return;
        }

        const value = copyBtn.dataset.secretValue || '';
        try {
            await navigator.clipboard.writeText(value);
            copyBtn.classList.add('success');

            const icon = copyBtn.querySelector('[data-lucide]');
            if (icon) {
                icon.setAttribute('data-lucide', 'check');
                refreshIcons();
            }

            setTimeout(() => {
                copyBtn.classList.remove('success');
                const resetIcon = copyBtn.querySelector('[data-lucide]');
                if (resetIcon) {
                    resetIcon.setAttribute('data-lucide', 'copy');
                    refreshIcons();
                }
            }, 2000);
        } catch (error) {
            alert('Unable to copy value.');
        }
    });

    document.body.addEventListener('click', (event) => {
        const historyRow = event.target.closest('.js-history-row');
        if (!historyRow) {
            return;
        }

        openHistoryModal(
            historyRow.dataset.historySecret || '',
            historyRow.dataset.historyService || '',
            historyRow.dataset.historyRotated || ''
        );
    });

    document.body.addEventListener('click', async (event) => {
        const syncBtn = event.target.closest('#syncCacheBtn');
        if (!syncBtn) {
            return;
        }

        syncBtn.disabled = true;
        const originalText = syncBtn.textContent;
        syncBtn.textContent = 'Syncing...';

        try {
            const serviceId = syncBtn.dataset.serviceId || '';
            const formData = new FormData();
            formData.append('scope', 'all');
            formData.append('csrf_token', csrfToken);
            if (serviceId) {
                formData.append('serviceId', serviceId);
            }

            const refreshResponse = await fetch('/api/cache/refresh', {
                method: 'POST',
                body: formData,
            });
            const refreshPayload = await refreshResponse.json();
            if (!refreshResponse.ok || !refreshPayload.success) {
                showToast(refreshPayload.error || 'Cache refresh failed', 'error');
                return;
            }

            const params = new URLSearchParams();
            if (serviceId) {
                params.set('serviceId', serviceId);
            }
            params.set('refresh', '1');
            const tableResponse = await fetch(`/api/secrets-table?${params.toString()}`);
            if (!tableResponse.ok) {
                showToast('Cache refreshed but table reload failed', 'error');
                return;
            }

            const tableBody = document.getElementById('secrets-table-body');
            if (tableBody) {
                tableBody.innerHTML = await tableResponse.text();
                refreshIcons();
                applyFilters();
            }

            const mainContent = document.getElementById('mainContent');
            if (mainContent) {
                mainContent.dataset.cacheFetchedAt = String(Math.floor(Date.now() / 1000));
            }
            updateCacheStatus();

            showToast('Cache refreshed from Railway', 'success');
            const currentSection = mainContent ? (mainContent.dataset.section || 'secrets') : 'secrets';
            const nextUrl = serviceId
                ? `/?serviceId=${encodeURIComponent(serviceId)}&refresh=1${currentSection === 'history' ? '&section=history' : ''}`
                : `/?refresh=1${currentSection === 'history' ? '&section=history' : ''}`;
            window.setTimeout(() => {
                window.location.href = nextUrl;
            }, 250);
        } catch (error) {
            showToast('Unable to refresh cache', 'error');
        } finally {
            syncBtn.disabled = false;
            syncBtn.textContent = originalText;
        }
    });

    // Close modal after successful form submit/delete inside modal.
    document.body.addEventListener('htmx:afterRequest', (event) => {
        const detail = event.detail || {};
        const source = detail.elt;
        const successful = Boolean(detail.successful);
        if (!successful || !source) {
            return;
        }

        if (source.closest && source.closest('#configModal')) {
            closeConfigModal();
        }
    });

    document.body.addEventListener('htmx:responseError', () => {
        showToast('Request failed. Please retry.', 'error');
    });

    document.body.addEventListener('rotatorToast', (event) => {
        const detail = event.detail || {};
        const message = detail.message || detail.value || 'Done';
        const type = detail.type || 'success';
        showToast(message, type);
    });

    document.body.addEventListener('click', async (event) => {
        const editBtn = event.target.closest('.service-group-edit');
        if (!editBtn) {
            return;
        }

        const serviceId = editBtn.dataset.serviceId || '';
        const serviceName = editBtn.dataset.serviceName || 'service';
        const currentGroup = editBtn.dataset.groupName || '';
        const entered = window.prompt(`Set custom group for ${serviceName} (empty = no group)`, currentGroup === 'Ungrouped' || currentGroup === 'Services' ? '' : currentGroup);
        if (entered === null) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('serviceId', serviceId);
            formData.append('groupName', entered.trim());
            formData.append('csrf_token', csrfToken);

            const response = await fetch('/api/service-group', {
                method: 'POST',
                body: formData,
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                showToast(payload.error || 'Unable to update service group', 'error');
                return;
            }

            showToast('Service group updated', 'success');
            window.location.reload();
        } catch (error) {
            showToast('Unable to update service group', 'error');
        }
    });
});
