// Service group CRUD: create / edit / delete group + modal form submit

import { showToast, escapeHtml, getCsrfToken } from './utils.js';

function collectSidebarServices() {
    const servicesById = new Map();
    document.querySelectorAll('.js-grouped-service').forEach((link) => {
        const id   = link.dataset.serviceId   || '';
        const name = link.dataset.serviceName || '';
        if (!id || !name) return;
        servicesById.set(id, { id, name });
    });
    return Array.from(servicesById.values()).sort((a, b) => a.name.localeCompare(b.name));
}

function renderGroupServices(groupServicesList, services, selectedIds) {
    if (!groupServicesList) return;
    if (services.length === 0) {
        groupServicesList.innerHTML = '<div class="form-hint">No services available.</div>';
        return;
    }
    groupServicesList.innerHTML = services.map((service) => {
        const checked = selectedIds.has(service.id) ? 'checked' : '';
        return `<label class="group-service-option" style="display:flex;align-items:center;gap:8px;padding:6px 4px;cursor:pointer;">
            <input type="checkbox" name="serviceIds[]" value="${escapeHtml(service.id)}" ${checked}>
            <span>${escapeHtml(service.name)}</span>
        </label>`;
    }).join('');
}

function openGroupModal(groupModal, groupModalTitle, groupNameInput, groupServicesList, options) {
    if (!groupModal || !groupModalTitle || !groupNameInput) return;

    const title              = options.title || 'Create Group';
    const groupName          = options.groupName || '';
    const selectedServiceIds = new Set(options.selectedServiceIds || []);

    groupModalTitle.textContent = title;
    groupNameInput.value        = groupName;
    renderGroupServices(groupServicesList, collectSidebarServices(), selectedServiceIds);

    groupModal.classList.add('open');
    window.setTimeout(() => groupNameInput.focus(), 20);
}

export function initGroupHandlers() {
    const groupModal       = document.getElementById('groupModal');
    const groupModalTitle  = document.getElementById('groupModalTitle');
    const groupNameInput   = document.getElementById('groupNameInput');
    const groupServicesList = document.getElementById('groupServicesList');
    const groupModalForm   = document.getElementById('groupModalForm');

    document.body.addEventListener('click', async (event) => {
        const createBtn = event.target.closest('.js-create-group');
        if (createBtn) {
            openGroupModal(groupModal, groupModalTitle, groupNameInput, groupServicesList, {
                title: 'Create Group',
                groupName: '',
                selectedServiceIds: [],
            });
            return;
        }

        const editBtn = event.target.closest('.js-edit-group');
        if (editBtn) {
            const currentGroup       = editBtn.dataset.groupName || '';
            const serviceIdsCsv      = editBtn.dataset.serviceIds || '';
            const selectedServiceIds = serviceIdsCsv.split(',').map(id => id.trim()).filter(id => id.length > 0);
            const initialGroupName   = (currentGroup === 'Services' || currentGroup === 'Ungrouped') ? '' : currentGroup;
            openGroupModal(groupModal, groupModalTitle, groupNameInput, groupServicesList, {
                title: `Edit Group: ${currentGroup}`,
                groupName: initialGroupName,
                selectedServiceIds,
            });
            return;
        }

        const deleteBtn = event.target.closest('.js-delete-config');
        if (!deleteBtn) return;

        const secretName = deleteBtn.dataset.secretName || 'this secret';
        const deleteUrl  = deleteBtn.dataset.deleteUrl  || '';

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
                } catch {
                    showToast('Unable to delete config', 'error');
                }
            }
        );
    });

    if (!groupModalForm) return;

    groupModalForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const groupName = (groupNameInput ? groupNameInput.value : '').trim();
        if (groupName === '') {
            showToast('Group name is required', 'error');
            return;
        }

        const selectedServiceInputs = Array.from(
            groupModalForm.querySelectorAll('input[name="serviceIds[]"]:checked')
        );
        if (selectedServiceInputs.length === 0) {
            showToast('Select at least one service', 'error');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('groupName', groupName);
            formData.append('csrf_token', getCsrfToken());
            selectedServiceInputs.forEach((input) => formData.append('serviceIds[]', input.value));

            const response = await fetch('/api/service-group/bulk', { method: 'POST', body: formData });
            const payload  = await response.json();
            if (!response.ok || !payload.success) {
                showToast(payload.error || 'Unable to save group', 'error');
                return;
            }

            if (groupModal) groupModal.classList.remove('open');
            showToast('Group saved', 'success');
            window.location.reload();
        } catch {
            showToast('Unable to save group', 'error');
        }
    });
}
