// Dashboard interactions - CSP-safe HTMX helpers
document.addEventListener('DOMContentLoaded', () => {
    const configModal = document.getElementById('configModal');

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
        document.querySelectorAll('.js-scope-nav').forEach((link) => {
            const linkServiceId = link.dataset.serviceId || '';
            link.classList.toggle('active', linkServiceId === currentServiceId);
        });
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

    // Make available for any future non-inline integrations.
    window.filterRailwayVars = applyFilters;

    refreshIcons();
    applyFilters();
    updateSidebarActive();
    updateDocumentTitle();

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

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeConfigModal();
        }
    });

    // HTMX lifecycle hooks for dynamic fragments.
    document.body.addEventListener('htmx:afterSwap', () => {
        refreshIcons();
        applyFilters();
        updateSidebarActive();
        updateDocumentTitle();
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
});
