// Secret reveal, copy, and auto-hide

import { showToast, refreshIcons } from './utils.js';

const autoHideTimers = new WeakMap();
const AUTO_HIDE_MS   = 30_000;

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

export async function fetchSecretValue(secretName, serviceId) {
    const params   = new URLSearchParams({ name: secretName, serviceId: serviceId || '' });
    const response = await fetch(`/api/secret-value?${params}`);
    const payload  = await response.json();
    if (!response.ok || !payload.success) throw new Error(payload.error || 'Failed to fetch value');
    return payload.value;
}

export function initSecretHandlers() {
    // Toggle reveal / hide
    document.body.addEventListener('click', async (event) => {
        const toggleBtn = event.target.closest('.js-toggle-secret');
        if (!toggleBtn) return;

        const row  = toggleBtn.closest('.secret-row');
        if (!row) return;
        const span = row.querySelector('.secret-value');
        if (!span) return;

        if (span.dataset.loaded === '1') { obscureSpan(span, toggleBtn); return; }

        const secretName = toggleBtn.dataset.secretName || '';
        const serviceId  = toggleBtn.dataset.serviceId  || '';
        const icon       = toggleBtn.querySelector('[data-lucide]');
        if (icon) { icon.setAttribute('data-lucide', 'loader-circle'); refreshIcons(); }
        toggleBtn.disabled = true;

        try {
            const value = await fetchSecretValue(secretName, serviceId);
            span.textContent  = value;
            span.dataset.loaded = '1';
            span.classList.add('revealed');
            if (icon) { icon.setAttribute('data-lucide', 'eye-off'); refreshIcons(); }
            scheduleAutoHide(span, toggleBtn);
        } catch {
            showToast('Could not fetch value', 'error');
            if (icon) { icon.setAttribute('data-lucide', 'eye'); refreshIcons(); }
        } finally {
            toggleBtn.disabled = false;
        }
    });

    // Copy secret value
    document.body.addEventListener('click', async (event) => {
        const copyBtn = event.target.closest('.js-copy-secret');
        if (!copyBtn) return;

        const secretName = copyBtn.dataset.secretName || '';
        const serviceId  = copyBtn.dataset.serviceId  || '';
        const row        = copyBtn.closest('.secret-row');
        const span       = row ? row.querySelector('.secret-value') : null;

        try {
            let value;
            if (span && span.dataset.loaded === '1') {
                value = span.textContent;
            } else {
                value = await fetchSecretValue(secretName, serviceId);
                if (span) {
                    const tbtn = row.querySelector('.js-toggle-secret');
                    span.textContent    = value;
                    span.dataset.loaded = '1';
                    span.classList.add('revealed');
                    const icon = tbtn && tbtn.querySelector('[data-lucide]');
                    if (icon) { icon.setAttribute('data-lucide', 'eye-off'); refreshIcons(); }
                    scheduleAutoHide(span, tbtn);
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
}
