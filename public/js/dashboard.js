// Dashboard interactions - CSP-safe HTMX helpers

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = String(str ?? '');
    return div.innerHTML;
}

document.addEventListener('DOMContentLoaded', () => {
    const configModal = document.getElementById('configModal');
    const historyModal = document.getElementById('historyModal');
    const groupModal = document.getElementById('groupModal');
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

        document.querySelectorAll('.js-overview-nav').forEach((link) => {
            link.classList.toggle('active', currentSection === 'overview');
        });

        document.querySelectorAll('.js-scope-nav').forEach((link) => {
            const linkServiceId = link.dataset.serviceId || '';
            link.classList.toggle('active', currentSection === 'secrets' && linkServiceId === currentServiceId);
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

    function openHistoryModal(secret, service, rotatedAt, oldValue, newValue, newValueIsLive, triggerType) {
        if (!historyModal) {
            return;
        }

        const secretEl = document.getElementById('historyDetailSecret');
        const serviceEl = document.getElementById('historyDetailService');
        const triggerEl = document.getElementById('historyDetailTrigger');
        const timeEl = document.getElementById('historyDetailTime');
        const oldValueEl = document.getElementById('historyDetailOldValue');
        const newValueEl = document.getElementById('historyDetailNewValue');
        const newValueHint = document.getElementById('historyDetailNewValueHint');

        if (secretEl) secretEl.textContent = secret || '--';
        if (serviceEl) serviceEl.textContent = service || '--';
        if (timeEl) timeEl.textContent = rotatedAt || '--';

        if (triggerEl) {
            const isAuto = triggerType === 'auto';
            triggerEl.innerHTML = `<span class="history-trigger-badge ${isAuto ? 'trigger-auto' : 'trigger-manual'}">${isAuto ? 'Automatic (cron)' : 'Manual'}</span>`;
        }

        if (oldValueEl) {
            if (oldValue) {
                oldValueEl.textContent = oldValue;
                oldValueEl.classList.remove('unavailable');
            } else {
                oldValueEl.textContent = 'Unavailable';
                oldValueEl.classList.add('unavailable');
            }
        }

        if (newValueEl) {
            if (newValue) {
                newValueEl.textContent = newValue;
                newValueEl.classList.remove('unavailable');
            } else {
                newValueEl.textContent = 'Not yet available — rotate again to backfill';
                newValueEl.classList.add('unavailable');
            }
        }

        if (newValueHint) {
            if (newValueIsLive) {
                newValueHint.textContent = 'live from Railway';
                newValueHint.className = 'history-values-hint live-badge';
            } else {
                newValueHint.textContent = '(after rotation)';
                newValueHint.className = 'history-values-hint';
            }
        }

        historyModal.classList.add('open');
    }

    function closeHistoryModal() {
        if (historyModal) {
            historyModal.classList.remove('open');
        }
    }

    function closeGroupModal() {
        if (groupModal) {
            groupModal.classList.remove('open');
        }
    }

    // Make available for any future non-inline integrations.
    window.filterRailwayVars = applyFilters;

    function relativeTime(dateStr) {
        if (!dateStr) return dateStr;
        const date = new Date(dateStr.replace(' ', 'T') + 'Z');
        if (isNaN(date.getTime())) return dateStr;
        const diffMs = Date.now() - date.getTime();
        const diffSec = Math.floor(diffMs / 1000);
        if (diffSec < 60) return 'just now';
        const diffMin = Math.floor(diffSec / 60);
        if (diffMin < 60) return diffMin + 'm ago';
        const diffHr = Math.floor(diffMin / 60);
        if (diffHr < 24) return diffHr + 'h ago';
        const diffDay = Math.floor(diffHr / 24);
        if (diffDay < 30) return diffDay + 'd ago';
        return date.toLocaleDateString();
    }

    function applyRelativeTimes() {
        document.querySelectorAll('.js-relative-time[data-timestamp]').forEach((el) => {
            const raw = el.dataset.timestamp;
            el.textContent = relativeTime(raw);
            el.title = raw;
        });
    }

    refreshIcons();
    applyFilters();
    applyRelativeTimes();
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

    if (groupModal) {
        groupModal.addEventListener('click', (event) => {
            if (event.target === groupModal) {
                closeGroupModal();
            }
        });
    }

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeConfigModal();
            closeHistoryModal();
            closeGroupModal();
        }
    });

    // HTMX lifecycle hooks for dynamic fragments.
    document.body.addEventListener('htmx:afterSwap', () => {
        refreshIcons();
        applyFilters();
        applyRelativeTimes();
        updateSidebarActive();
        updateDocumentTitle();
        updateCacheStatus();
    });
    document.body.addEventListener('htmx:afterSettle', () => {
        refreshIcons();
        applyRelativeTimes();
    });

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

        const closeGroupBtn = event.target.closest('.js-close-group-modal');
        if (closeGroupBtn) {
            closeGroupModal();
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
        const copyBtn = event.target.closest('.js-copy-history-value');
        if (!copyBtn) {
            return;
        }

        const targetId = copyBtn.dataset.target || '';
        const targetEl = targetId ? document.getElementById(targetId) : null;
        if (!targetEl) {
            return;
        }

        const value = targetEl.textContent || '';
        if (targetEl.classList.contains('unavailable') || !value.trim()) {
            showToast('No value to copy', 'error');
            return;
        }

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

    document.body.addEventListener('click', async (event) => {
        const valuesBtn = event.target.closest('.js-history-values-btn');
        if (!valuesBtn) {
            return;
        }

        const historyId = valuesBtn.dataset.historyId || '';
        if (!historyId) {
            showToast('Missing history entry id', 'error');
            return;
        }

        try {
            const response = await fetch(`/api/rotation-history-detail?id=${encodeURIComponent(historyId)}`);
            const payload = await response.json();
            if (!response.ok || !payload.success || !payload.data) {
                showToast(payload.error || 'Unable to load history values', 'error');
                return;
            }

            const data = payload.data;
            openHistoryModal(
                data.secret_name || '',
                data.service || '',
                data.rotated_at || '',
                data.old_value || '',
                data.new_value || '',
                !!data.new_value_is_live,
                data.trigger_type || 'manual'
            );
        } catch (error) {
            showToast('Unable to load history values', 'error');
        }
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
            const sectionParam = currentSection !== 'secrets' ? `&section=${encodeURIComponent(currentSection)}` : '';
            const nextUrl = serviceId
                ? `/?serviceId=${encodeURIComponent(serviceId)}&refresh=1${sectionParam}`
                : `/?refresh=1${sectionParam}`;
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

    const groupModalForm = document.getElementById('groupModalForm');
    const groupModalTitle = document.getElementById('groupModalTitle');
    const groupNameInput = document.getElementById('groupNameInput');
    const groupServicesList = document.getElementById('groupServicesList');

    function collectSidebarServices() {
        const servicesById = new Map();
        document.querySelectorAll('.js-grouped-service').forEach((link) => {
            const id = link.dataset.serviceId || '';
            const name = link.dataset.serviceName || '';
            const currentGroup = link.dataset.groupName || '';
            if (!id || !name) {
                return;
            }
            servicesById.set(id, { id, name, currentGroup });
        });
        return Array.from(servicesById.values()).sort((a, b) => a.name.localeCompare(b.name));
    }

    function renderGroupServices(services, selectedIds) {
        if (!groupServicesList) {
            return;
        }

        if (services.length === 0) {
            groupServicesList.innerHTML = '<div class="form-hint">No services available.</div>';
            return;
        }

        const rows = services.map((service) => {
            const checked = selectedIds.has(service.id) ? 'checked' : '';
            return `<label class="group-service-option" style="display:flex;align-items:center;gap:8px;padding:6px 4px;cursor:pointer;">
                <input type="checkbox" name="serviceIds[]" value="${escapeHtml(service.id)}" ${checked}>
                <span>${escapeHtml(service.name)}</span>
            </label>`;
        });
        groupServicesList.innerHTML = rows.join('');
    }

    function openGroupModal(options) {
        if (!groupModal || !groupModalTitle || !groupNameInput) {
            return;
        }

        const title = options.title || 'Create Group';
        const groupName = options.groupName || '';
        const selectedServiceIds = new Set(options.selectedServiceIds || []);
        const services = collectSidebarServices();

        groupModalTitle.textContent = title;
        groupNameInput.value = groupName;
        renderGroupServices(services, selectedServiceIds);

        groupModal.classList.add('open');
        window.setTimeout(() => groupNameInput.focus(), 20);
    }

    document.body.addEventListener('click', (event) => {
        const createBtn = event.target.closest('.js-create-group');
        if (createBtn) {
            openGroupModal({
                title: 'Create Group',
                groupName: '',
                selectedServiceIds: [],
            });
            return;
        }

        const editBtn = event.target.closest('.js-edit-group');
        if (editBtn) {
            const currentGroup = editBtn.dataset.groupName || '';
            const serviceIdsCsv = editBtn.dataset.serviceIds || '';
            const selectedServiceIds = serviceIdsCsv
                .split(',')
                .map((id) => id.trim())
                .filter((id) => id.length > 0);
            const initialGroupName = (currentGroup === 'Services' || currentGroup === 'Ungrouped') ? '' : currentGroup;
            openGroupModal({
                title: `Edit Group: ${currentGroup}`,
                groupName: initialGroupName,
                selectedServiceIds,
            });
            return;
        }

        const deleteBtn = event.target.closest('.js-delete-config');
        if (!deleteBtn) {
            return;
        }

        const secretName = deleteBtn.dataset.secretName || 'this secret';
        const deleteUrl = deleteBtn.dataset.deleteUrl || '';

        window.confirmDialog(
            'Stop Managing Secret',
            `Stop managing '${secretName}'? This will not delete the variable itself.`,
            async () => {
                try {
                    const response = await fetch(deleteUrl, {
                        method: 'DELETE',
                        headers: { 'HX-Request': 'true' },
                    });
                    const html = await response.text();
                    const secretsTable = document.getElementById('secrets-table-body');
                    if (secretsTable) {
                        secretsTable.innerHTML = html;
                        htmx.process(secretsTable);
                    }
                } catch (error) {
                    showToast('Unable to delete config', 'error');
                }
            }
        );
    });

    if (groupModalForm) {
        groupModalForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const groupName = (groupNameInput ? groupNameInput.value : '').trim();
            if (groupName === '') {
                showToast('Group name is required', 'error');
                return;
            }

            const selectedServiceInputs = Array.from(groupModalForm.querySelectorAll('input[name="serviceIds[]"]:checked'));
            if (selectedServiceInputs.length === 0) {
                showToast('Select at least one service', 'error');
                return;
            }

            try {
                const formData = new FormData();
                formData.append('groupName', groupName);
                formData.append('csrf_token', csrfToken);
                selectedServiceInputs.forEach((input) => {
                    formData.append('serviceIds[]', input.value);
                });

                const response = await fetch('/api/service-group/bulk', {
                    method: 'POST',
                    body: formData,
                });
                const payload = await response.json();
                if (!response.ok || !payload.success) {
                    showToast(payload.error || 'Unable to save group', 'error');
                    return;
                }

                if (groupModal) {
                    groupModal.classList.remove('open');
                }
                showToast('Group saved', 'success');
                window.location.reload();
            } catch (error) {
                showToast('Unable to save group', 'error');
            }
        });
    }
});
