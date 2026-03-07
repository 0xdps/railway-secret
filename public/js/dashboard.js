document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const currentServiceId = body.dataset.currentServiceId || '';
    const csrfToken = body.dataset.csrfToken || '';

    const configModal = document.getElementById('configModal');
    const confirmModal = document.getElementById('confirmModal');
    const configForm = document.getElementById('configForm');
    const deleteZone = document.getElementById('deleteZone');
    const manualGroup = document.getElementById('manualValueGroup');

    const hasModalUi = Boolean(configModal && confirmModal && configForm && deleteZone && manualGroup);

    function refreshIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    refreshIcons();

    const refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => window.location.reload());
    }

    const toggleRailway = document.getElementById('toggleRailway');
    function filterRailwayVars() {
        if (!toggleRailway) {
            return;
        }

        const show = toggleRailway.checked;
        const rows = document.querySelectorAll('.secret-tr[data-is-railway="1"]');
        rows.forEach((row) => {
            row.style.display = show ? '' : 'none';
        });
    }

    if (toggleRailway) {
        toggleRailway.addEventListener('change', filterRailwayVars);
    }
    filterRailwayVars();

    function closeModal() {
        if (!configModal) {
            return;
        }
        configModal.classList.remove('open');
    }

    function openModal(key, existingConfig) {
        if (!configForm || !deleteZone || !manualGroup || !configModal) {
            return;
        }

        configForm.elements.name.value = key;
        const title = document.getElementById('modalTitle');
        if (title) {
            title.textContent = `Rotate & Configure: ${key}`;
        }

        manualGroup.style.display = 'block';

        if (existingConfig) {
            configForm.elements.length.value = existingConfig.length;
            configForm.elements.encoding.value = existingConfig.encoding;
            configForm.elements.interval.value = existingConfig.interval_days;
            deleteZone.style.display = 'block';
        } else {
            configForm.reset();
            configForm.elements.name.value = key;
            configForm.elements.serviceId.value = currentServiceId;
            configForm.elements.csrf_token.value = csrfToken;
            configForm.elements.length.value = '32';
            configForm.elements.encoding.value = 'hex';
            configForm.elements.interval.value = '30';
            deleteZone.style.display = 'none';
        }

        configModal.classList.add('open');
    }

    function customConfirm({
        title = 'Are you sure?',
        message = '',
        confirmText = 'Confirm',
        danger = true,
    } = {}) {
        return new Promise((resolve) => {
            if (!confirmModal) {
                resolve(false);
                return;
            }

            const titleEl = document.getElementById('confirmTitle');
            const msgEl = document.getElementById('confirmMessage');
            const okBtn = document.getElementById('confirmOkBtn');
            const cancelBtn = document.getElementById('confirmCancelBtn');
            const icon = document.getElementById('confirmIcon');

            if (!titleEl || !msgEl || !okBtn || !cancelBtn || !icon) {
                resolve(false);
                return;
            }

            titleEl.textContent = title;
            msgEl.textContent = message;
            okBtn.textContent = confirmText;

            okBtn.className = `btn ${danger ? 'btn-danger' : 'btn-primary'} btn-md`;
            okBtn.style.flex = '1';
            icon.style.color = danger ? 'var(--danger)' : 'var(--accent)';
            const iconBox = icon.closest('div');
            if (iconBox) {
                iconBox.style.background = danger ? 'var(--danger-bg)' : 'var(--bg-active)';
                iconBox.style.borderColor = danger ? 'rgba(239,68,68,0.2)' : 'rgba(99,102,241,0.2)';
            }

            confirmModal.classList.add('open');
            refreshIcons();

            function cleanup(result) {
                confirmModal.classList.remove('open');
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                resolve(result);
            }

            const onOk = () => cleanup(true);
            const onCancel = () => cleanup(false);

            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);

            confirmModal.addEventListener(
                'click',
                (event) => {
                    if (event.target === confirmModal) {
                        cleanup(false);
                    }
                },
                { once: true }
            );
        });
    }

    async function deleteConfig() {
        if (!configForm) {
            return;
        }

        const ok = await customConfirm({
            title: 'Stop rotating?',
            message: 'This secret will no longer be auto-rotated. You can re-configure it at any time.',
            confirmText: 'Stop Rotating',
        });

        if (!ok) {
            return;
        }

        const key = configForm.elements.name.value;
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('name', key);
        formData.append('csrf_token', csrfToken);
        if (currentServiceId) {
            formData.append('serviceId', currentServiceId);
        }

        try {
            const response = await fetch('/api/manage', { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.error || 'Action failed');
            }
        } catch (error) {
            alert(error.message);
        }
    }

    const closeConfigModalBtn = document.getElementById('closeConfigModalBtn');
    if (closeConfigModalBtn) {
        closeConfigModalBtn.addEventListener('click', closeModal);
    }

    if (configModal) {
        configModal.addEventListener('click', (event) => {
            if (event.target === configModal) {
                closeModal();
            }
        });
    }

    const deleteConfigBtn = document.getElementById('deleteConfigBtn');
    if (deleteConfigBtn) {
        deleteConfigBtn.addEventListener('click', deleteConfig);
    }

    if (hasModalUi) {
        document.querySelectorAll('.js-open-config').forEach((button) => {
            button.addEventListener('click', () => {
                const key = button.dataset.key || '';
                let existingConfig = null;
                const rawConfig = button.dataset.config;

                if (rawConfig && rawConfig !== 'null') {
                    try {
                        existingConfig = JSON.parse(rawConfig);
                    } catch (error) {
                        existingConfig = null;
                    }
                }

                openModal(key, existingConfig);
            });
        });
    }

    document.querySelectorAll('.js-toggle-secret').forEach((button) => {
        button.addEventListener('click', () => {
            const row = button.closest('.secret-row');
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

            const icon = button.querySelector('[data-lucide]');
            if (icon) {
                icon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
                refreshIcons();
            }
        });
    });

    document.querySelectorAll('.js-copy-secret').forEach((button) => {
        button.addEventListener('click', async () => {
            const value = button.dataset.secretValue || '';
            try {
                await navigator.clipboard.writeText(value);
                button.classList.add('success');

                const icon = button.querySelector('[data-lucide]');
                if (icon) {
                    icon.setAttribute('data-lucide', 'check');
                    refreshIcons();
                }

                window.setTimeout(() => {
                    button.classList.remove('success');

                    const resetIcon = button.querySelector('[data-lucide]');
                    if (resetIcon) {
                        resetIcon.setAttribute('data-lucide', 'copy');
                        refreshIcons();
                    }
                }, 2000);
            } catch (error) {
                alert('Unable to copy value.');
            }
        });
    });

    if (configForm) {
        configForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submitter = event.submitter || document.activeElement;
        const action = submitter ? submitter.value : '0';
        const manualValueInput = configForm.elements.manualValue;
        const manualValue = manualValueInput ? manualValueInput.value : '';

        const submitButtons = configForm.querySelectorAll('button[type="submit"]');
        submitButtons.forEach((btn) => {
            btn.disabled = true;
        });

        const originalText = submitter ? submitter.textContent : '';
        if (submitter) {
            submitter.textContent = 'Processing...';
        }

        const formData = new FormData(configForm);

        function resetButtons() {
            submitButtons.forEach((btn) => {
                btn.disabled = false;
            });
            if (submitter) {
                submitter.textContent = originalText;
            }
        }

        try {
            if (action !== 'rotate-only') {
                const saveData = new FormData(configForm);
                saveData.append('action', 'save');
                const saveResponse = await fetch('/api/manage', { method: 'POST', body: saveData });
                const savePayload = await saveResponse.json();
                if (!savePayload.success) {
                    alert(savePayload.error || 'Save failed');
                    resetButtons();
                    return;
                }
            }

            if (action === '1' || action === 'rotate-only') {
                const rotateData = new FormData();
                rotateData.append('key', formData.get('name'));
                rotateData.append('serviceId', formData.get('serviceId'));
                rotateData.append('length', formData.get('length'));
                rotateData.append('encoding', formData.get('encoding'));
                rotateData.append('csrf_token', csrfToken);
                if (manualValue) {
                    rotateData.append('manualValue', manualValue);
                }

                const rotateResponse = await fetch('/api/rotate', { method: 'POST', body: rotateData });
                const rotatePayload = await rotateResponse.json();
                if (!rotatePayload.success) {
                    alert(`Action failed: ${rotatePayload.error || 'Unknown error'}`);
                    resetButtons();
                    return;
                }
            }

            window.location.reload();
        } catch (error) {
            alert(error.message || 'Action failed');
            resetButtons();
        }
        });
    }

    window.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        if (configModal && configModal.classList.contains('open')) {
            closeModal();
        }

        if (confirmModal && confirmModal.classList.contains('open')) {
            const cancel = document.getElementById('confirmCancelBtn');
            if (cancel) {
                cancel.click();
            }
        }
    });
});
