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

    window.confirmDialog = function(title, message, onConfirm) {
        const modal = document.getElementById('confirmationModal');
        const titleEl = document.getElementById('confirmationTitle');
        const msgEl = document.getElementById('confirmationMessage');
        const okBtn = document.getElementById('confirmationOkBtn');

        titleEl.textContent = title;
        msgEl.innerHTML = message;

        const handler = () => {
            okBtn.removeEventListener('click', handler);
            modal.classList.remove('open');
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        };

        okBtn.addEventListener('click', handler);
        modal.classList.add('open');
    };

    // Modal close handlers (moved from inline script to satisfy script-src CSP)
    document.querySelectorAll('.js-close-confirmation').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('confirmationModal').classList.remove('open');
        });
    });

    document.querySelectorAll('.js-close-group-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('groupModal').classList.remove('open');
        });
    });

    document.querySelectorAll('.js-close-history-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('historyModal').classList.remove('open');
        });
    });

    ['confirmationModal', 'configModal', 'historyModal', 'groupModal'].forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    modal.classList.remove('open');
                }
            });
        }
    });

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

        document.querySelectorAll('.js-managed-nav').forEach((link) => {
            link.classList.toggle('active', currentSection === 'managed');
        });

        document.querySelectorAll('.js-docs-nav').forEach((link) => {
            link.classList.toggle('active', currentSection === 'docs');
        });

        document.querySelectorAll('.js-about-nav').forEach((link) => {
            link.classList.toggle('active', currentSection === 'about');
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

    function openHistoryModal(secret, service, rotatedAt, oldValue, newValue, newValueIsLive, triggerType, historyId, serviceId) {
        if (!historyModal) return;

        const secretEl  = document.getElementById('historyDetailSecret');
        const serviceEl = document.getElementById('historyDetailService');
        const triggerEl = document.getElementById('historyDetailTrigger');
        const timeEl    = document.getElementById('historyDetailTime');
        const oldValueEl    = document.getElementById('historyDetailOldValue');
        const newValueEl    = document.getElementById('historyDetailNewValue');
        const newValueHint  = document.getElementById('historyDetailNewValueHint');
        const showCurrentBtn = document.getElementById('historyShowCurrentBtn');
        const rollbackBtn    = document.getElementById('historyRollbackBtn');

        if (secretEl)  secretEl.textContent  = secret  || '--';
        if (serviceEl) serviceEl.textContent = service || '--';
        if (timeEl)    timeEl.textContent    = rotatedAt || '--';

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
                if (showCurrentBtn) showCurrentBtn.style.display = 'none';
            } else {
                newValueEl.textContent = 'Not yet available';
                newValueEl.classList.add('unavailable');
                // Show opt-in button to fetch live value from Railway
                if (showCurrentBtn) {
                    showCurrentBtn.style.display = '';
                    showCurrentBtn.dataset.historyId = historyId || '';
                }
            }
        }

        if (newValueHint) {
            newValueHint.textContent = newValue ? '(after rotation)' : '';
            newValueHint.className = 'history-values-hint';
        }

        // Populate rollback button — only show when there's an old value to restore
        if (rollbackBtn) {
            rollbackBtn.dataset.secretName = secret || '';
            rollbackBtn.dataset.serviceId  = serviceId || '';
            rollbackBtn.dataset.historyId  = historyId || '';
            rollbackBtn.style.display = oldValue ? '' : 'none';
        }

        historyModal.classList.add('open');
    }

    // Opt-in: fetch the live current value from Railway only when the user asks
    document.body.addEventListener('click', async (event) => {
        const btn = event.target.closest('#historyShowCurrentBtn');
        if (!btn) return;
        const historyId = btn.dataset.historyId || '';
        if (!historyId) return;

        btn.disabled = true;
        btn.textContent = 'Fetching…';
        try {
            const response = await fetch(`/api/rotation-history-detail?id=${encodeURIComponent(historyId)}&fetch_live=1`);
            const payload = await response.json();
            if (!response.ok || !payload.success || !payload.data) {
                showToast(payload.error || 'Unable to fetch live value', 'error');
                btn.disabled = false;
                btn.textContent = 'Show current value';
                return;
            }
            const liveValue = payload.data.new_value;
            const newValueEl = document.getElementById('historyDetailNewValue');
            const newValueHint = document.getElementById('historyDetailNewValueHint');
            if (newValueEl) {
                if (liveValue) {
                    newValueEl.textContent = liveValue;
                    newValueEl.classList.remove('unavailable');
                    if (newValueHint) {
                        newValueHint.textContent = 'live from Railway';
                        newValueHint.className = 'history-values-hint live-badge';
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

    function closeHistoryModal() {
        if (!historyModal) return;
        historyModal.classList.remove('open');
        // Clear sensitive values from DOM as soon as the modal closes
        const clearIds = ['historyDetailOldValue', 'historyDetailNewValue'];
        clearIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = '--';
        });
        const hint = document.getElementById('historyDetailNewValueHint');
        if (hint) { hint.textContent = ''; hint.className = 'history-values-hint'; }
        const showBtn = document.getElementById('historyShowCurrentBtn');
        if (showBtn) showBtn.style.display = '';
    }

    function closeGroupModal() {
        if (groupModal) {
            groupModal.classList.remove('open');
        }
    }

    // Make available for any future non-inline integrations.
    window.filterRailwayVars = applyFilters;

    function sortTable(sortKey) {
        const tbody = document.getElementById('secrets-table-body');
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll('.secret-tr'));
        rows.sort((a, b) => {
            const nameA = (a.dataset.keyName || '').toLowerCase();
            const nameB = (b.dataset.keyName || '').toLowerCase();
            const managedA = a.dataset.isManaged === '1';
            const managedB = b.dataset.isManaged === '1';
            switch (sortKey) {
                case 'name-desc':
                    return nameB.localeCompare(nameA);
                case 'managed-first':
                    if (managedA !== managedB) return managedA ? -1 : 1;
                    return nameA.localeCompare(nameB);
                case 'unmanaged-first':
                    if (managedA !== managedB) return managedA ? 1 : -1;
                    return nameA.localeCompare(nameB);
                default: // name-asc
                    return nameA.localeCompare(nameB);
            }
        });
        rows.forEach(row => tbody.appendChild(row));
    }

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
        initConfigModal();       // re-wire modal controls after HTMX swap
    });
    document.body.addEventListener('htmx:afterSettle', () => {
        refreshIcons();
        applyRelativeTimes();
    });

    // ── Secret reveal helpers ────────────────────────────────────────────────
    const autoHideTimers = new WeakMap();
    const AUTO_HIDE_MS = 30_000;

    function obscureSpan(span, toggleBtn) {
        span.textContent = '••••••••••••';
        span.classList.remove('revealed');
        span.removeAttribute('data-loaded');
        const icon = toggleBtn && toggleBtn.querySelector('[data-lucide]');
        if (icon) { icon.setAttribute('data-lucide', 'eye'); refreshIcons(); }
        const timer = autoHideTimers.get(span);
        if (timer) { clearTimeout(timer); autoHideTimers.delete(span); }
    }

    function scheduleAutoHide(span, toggleBtn) {
        const existing = autoHideTimers.get(span);
        if (existing) clearTimeout(existing);
        autoHideTimers.set(span, setTimeout(() => obscureSpan(span, toggleBtn), AUTO_HIDE_MS));
    }

    async function fetchSecretValue(secretName, serviceId) {
        const params = new URLSearchParams({ name: secretName, serviceId: serviceId || '' });
        const response = await fetch(`/api/secret-value?${params}`);
        const payload = await response.json();
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Failed to fetch value');
        }
        return payload.value;
    }

    // Open/close modal controls without inline handlers.
    document.body.addEventListener('click', async (event) => {
        const sortBtn = event.target.closest('.sort-btn');
        if (sortBtn) {
            document.querySelectorAll('.sort-btn').forEach(b => b.classList.remove('active'));
            sortBtn.classList.add('active');
            sortTable(sortBtn.dataset.sort || 'name-asc');
            return;
        }

        const rotateDueBtn = event.target.closest('#rotateDueBtn');
        if (rotateDueBtn) {
            rotateDueBtn.disabled = true;
            const originalHtml = rotateDueBtn.innerHTML;
            rotateDueBtn.textContent = 'Rotating…';
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            fetch('/api/rotate-all-due', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(payload => {
                    if (!payload.success) {
                        showToast(payload.error || 'Rotation failed', 'error');
                        return;
                    }
                    showToast(payload.message || 'Done', 'success');
                    const mc = document.getElementById('mainContent');
                    if (mc) {
                        fetch('/managed', { headers: { 'HX-Request': 'true' } })
                            .then(r => r.text())
                            .then(html => {
                                mc.outerHTML = html;
                                refreshIcons();
                                applyRelativeTimes();
                                updateSidebarActive();
                            })
                            .catch(() => {});
                    }
                })
                .catch(() => showToast('Unable to rotate', 'error'))
                .finally(() => {
                    rotateDueBtn.disabled = false;
                    rotateDueBtn.innerHTML = originalHtml;
                    refreshIcons();
                });
            return;
        }

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
            if (!row) return;
            const span = row.querySelector('.secret-value');
            if (!span) return;

            const isRevealed = span.dataset.loaded === '1';
            if (isRevealed) {
                obscureSpan(span, toggleBtn);
                return;
            }

            // Not yet loaded — fetch from server
            const secretName = toggleBtn.dataset.secretName || '';
            const serviceId  = toggleBtn.dataset.serviceId  || '';
            const icon = toggleBtn.querySelector('[data-lucide]');
            if (icon) { icon.setAttribute('data-lucide', 'loader-circle'); refreshIcons(); }
            toggleBtn.disabled = true;

            try {
                const value = await fetchSecretValue(secretName, serviceId);
                span.textContent = value;
                span.dataset.loaded = '1';
                span.classList.add('revealed');
                if (icon) { icon.setAttribute('data-lucide', 'eye-off'); refreshIcons(); }
                scheduleAutoHide(span, toggleBtn);
            } catch (err) {
                showToast('Could not fetch value', 'error');
                if (icon) { icon.setAttribute('data-lucide', 'eye'); refreshIcons(); }
            } finally {
                toggleBtn.disabled = false;
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
        if (!copyBtn) return;

        const secretName = copyBtn.dataset.secretName || '';
        const serviceId  = copyBtn.dataset.serviceId  || '';
        const row = copyBtn.closest('.secret-row');
        const span = row ? row.querySelector('.secret-value') : null;

        try {
            let value;
            if (span && span.dataset.loaded === '1') {
                // Already revealed — use in-memory textContent, avoid extra round-trip
                value = span.textContent;
            } else {
                value = await fetchSecretValue(secretName, serviceId);
                // If the span exists, populate it and start auto-hide so the user
                // can also see it without needing to click the eye separately.
                if (span) {
                    const toggleBtn = row.querySelector('.js-toggle-secret');
                    span.textContent = value;
                    span.dataset.loaded = '1';
                    span.classList.add('revealed');
                    const icon = toggleBtn && toggleBtn.querySelector('[data-lucide]');
                    if (icon) { icon.setAttribute('data-lucide', 'eye-off'); refreshIcons(); }
                    scheduleAutoHide(span, toggleBtn);
                }
            }

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
            showToast('Unable to copy value', 'error');
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
                data.new_value || null,
                !!data.new_value_is_live,
                data.trigger_type || 'manual',
                data.id,
                data.service_id || ''
            );
        } catch (error) {
            showToast('Unable to load history values', 'error');
        }
    });

    // Rollback from history modal
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
                    fd.append('key', secretName);
                    fd.append('serviceId', serviceId);
                    fd.append('historyId', historyId);
                    fd.append('csrf_token', csrfToken);
                    const res = await fetch('/api/rollback', { method: 'POST', body: fd });
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

    // Rollback from secret row (undo last rotation)
    document.body.addEventListener('click', async (event) => {
        const btn = event.target.closest('.js-rollback-btn');
        if (!btn) return;

        const secretName = btn.dataset.secretName || '';
        const serviceId  = btn.dataset.serviceId  || '';
        if (!secretName) return;

        window.confirmDialog(
            'Rollback last rotation',
            `Restore <strong>${secretName}</strong> to the value it held before its last rotation? This will trigger a Railway redeploy.`,
            async () => {
                btn.disabled = true;
                try {
                    const fd = new FormData();
                    fd.append('key', secretName);
                    fd.append('serviceId', serviceId);
                    fd.append('csrf_token', csrfToken);
                    const res = await fetch('/api/rollback', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (!res.ok || !json.success) {
                        showToast(json.error || 'Rollback failed', 'error');
                    } else {
                        showToast(`${secretName} rolled back`, 'success');
                    }
                } catch {
                    showToast('Rollback failed', 'error');
                } finally {
                    btn.disabled = false;
                }
            }
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
            // On the managed page, refresh the overview table since #secrets-table-body doesn't exist there.
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

    // ── Config modal: schedule toggle ─────────────────────────────────────────
    function initConfigModal() {
        const toggle   = document.getElementById('scheduleToggle');
        const fields   = document.getElementById('scheduleFields');
        const fallback = document.getElementById('intervalFallback');

        if (toggle && fields && fallback) {
            function applyScheduleToggle() {
                const on = toggle.checked;
                fields.style.display = on ? '' : 'none';
                fallback.disabled    = on;
            }
            applyScheduleToggle();
            toggle.addEventListener('change', applyScheduleToggle);
        }
    }

    initConfigModal();
});
