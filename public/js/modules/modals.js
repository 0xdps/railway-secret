// Modal open/close and shared confirmDialog

export function openConfigModal() {
    document.getElementById('configModal')?.classList.add('open');
}

export function closeConfigModal() {
    document.getElementById('configModal')?.classList.remove('open');
}

export function closeGroupModal() {
    document.getElementById('groupModal')?.classList.remove('open');
}

export function closeHistoryModal() {
    const historyModal = document.getElementById('historyModal');
    if (!historyModal) return;
    historyModal.classList.remove('open');
    ['historyDetailOldValue', 'historyDetailNewValue'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.textContent = '--';
    });
    const hint = document.getElementById('historyDetailNewValueHint');
    if (hint) { hint.textContent = ''; hint.className = 'history-values-hint'; }
    const showBtn = document.getElementById('historyShowCurrentBtn');
    if (showBtn) showBtn.style.display = '';
}

export function openHistoryModal(secret, service, rotatedAt, oldValue, newValue, newValueIsLive, triggerType, historyId, serviceId) {
    const historyModal = document.getElementById('historyModal');
    if (!historyModal) return;

    const secretEl      = document.getElementById('historyDetailSecret');
    const serviceEl     = document.getElementById('historyDetailService');
    const triggerEl     = document.getElementById('historyDetailTrigger');
    const timeEl        = document.getElementById('historyDetailTime');
    const oldValueEl    = document.getElementById('historyDetailOldValue');
    const newValueEl    = document.getElementById('historyDetailNewValue');
    const newValueHint  = document.getElementById('historyDetailNewValueHint');
    const showCurrentBtn = document.getElementById('historyShowCurrentBtn');
    const rollbackBtn   = document.getElementById('historyRollbackBtn');

    if (secretEl)  secretEl.textContent  = secret   || '--';
    if (serviceEl) serviceEl.textContent = service  || '--';
    if (timeEl)    timeEl.textContent    = rotatedAt || '--';

    if (triggerEl) {
        const isAuto     = triggerType === 'auto';
        const isRollback = triggerType === 'rollback';
        const isSync     = triggerType === 'sync-manual' || triggerType === 'sync-auto';
        const badgeClass = isAuto     ? 'trigger-auto'
                         : isRollback ? 'trigger-rollback'
                         : isSync     ? 'trigger-sync'
                         :              'trigger-manual';
        const label = isAuto     ? 'Automatic (cron)'
                    : isRollback ? 'Rollback'
                    : triggerType === 'sync-auto' ? 'Sync Group (auto)'
                    : isSync                       ? 'Sync Group'
                    :                               'Manual';
        triggerEl.innerHTML = `<span class="history-trigger-badge ${badgeClass}">${label}</span>`;
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

    if (rollbackBtn) {
        rollbackBtn.dataset.secretName = secret    || '';
        rollbackBtn.dataset.serviceId  = serviceId || '';
        rollbackBtn.dataset.historyId  = historyId || '';
        rollbackBtn.style.display = oldValue ? '' : 'none';
    }

    historyModal.classList.add('open');
}

export function initModalHandlers() {
    // Static close buttons
    document.querySelectorAll('.js-close-confirmation').forEach((btn) => {
        btn.addEventListener('click', () => document.getElementById('confirmationModal')?.classList.remove('open'));
    });
    document.querySelectorAll('.js-close-group-modal').forEach((btn) => {
        btn.addEventListener('click', () => closeGroupModal());
    });
    document.querySelectorAll('.js-close-history-modal').forEach((btn) => {
        btn.addEventListener('click', () => closeHistoryModal());
    });

    // Backdrop click closes any modal
    ['confirmationModal', 'configModal', 'historyModal', 'groupModal'].forEach((modalId) => {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) modal.classList.remove('open');
            });
        }
    });

    // Escape key closes all modals
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeConfigModal();
            closeHistoryModal();
            closeGroupModal();
        }
    });

    // Reusable confirmation dialog
    window.confirmDialog = function (title, message, onConfirm) {
        const modal   = document.getElementById('confirmationModal');
        const titleEl = document.getElementById('confirmationTitle');
        const msgEl   = document.getElementById('confirmationMessage');
        const okBtn   = document.getElementById('confirmationOkBtn');

        titleEl.textContent = title;
        msgEl.innerHTML = message;

        const handler = () => {
            okBtn.removeEventListener('click', handler);
            modal.classList.remove('open');
            if (typeof onConfirm === 'function') onConfirm();
        };

        okBtn.addEventListener('click', handler);
        modal.classList.add('open');
    };
}
